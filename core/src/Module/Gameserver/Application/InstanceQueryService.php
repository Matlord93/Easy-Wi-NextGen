<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Gameserver\Application\Query\A2sQueryAdapter;
use App\Module\Gameserver\Application\Query\HttpQueryAdapter;
use App\Module\Gameserver\Application\Query\NoneQueryAdapter;
use App\Module\Gameserver\Application\Query\QueryAdapterInterface;
use App\Module\Gameserver\Application\Query\QueryContext;
use App\Module\Gameserver\Application\Query\QueryResult;
use App\Module\Gameserver\Application\Query\RconQueryAdapter;
use App\Module\Ports\Domain\Entity\PortBlock;
use Doctrine\ORM\EntityManagerInterface;

final class InstanceQueryService
{
    private const CACHE_TTL_SECONDS = 15;
    private const QUEUE_TTL_SECONDS = 12;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly HttpQueryAdapter $httpQueryAdapter,
        private readonly A2sQueryAdapter $a2sQueryAdapter,
        private readonly RconQueryAdapter $rconQueryAdapter,
        private readonly NoneQueryAdapter $noneQueryAdapter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(Instance $instance, ?PortBlock $portBlock, bool $queueIfStale = false): array
    {
        $config = $this->resolveQueryConfig($instance);
        $type = $config['type'];
        $checkedAt = $instance->getQueryCheckedAt();

        $cached = $this->buildSnapshot($instance->getQueryStatusCache(), $checkedAt, $type);
        if ($this->isFresh($checkedAt)) {
            return $cached;
        }

        if (!$queueIfStale || $type === 'none') {
            return $cached;
        }

        if ($type === 'http' && $this->shouldRunHttpQuery($config)) {
            return $this->runHttpQuery($instance, $portBlock, $config);
        }

        if ($instance->getStatus() !== InstanceStatus::Running) {
            return $cached;
        }

        if ($this->isQueueCooldown($instance->getQueryStatusCache())) {
            return $cached;
        }

        $this->queueQueryJob($instance, $portBlock, $config);

        return $this->buildSnapshot($instance->getQueryStatusCache(), $instance->getQueryCheckedAt(), $type);
    }

    /**
     * @return array{type: string, via: string, config: array<string, mixed>}
     */
    private function resolveQueryConfig(Instance $instance): array
    {
        $requirements = $instance->getTemplate()->getRequirements();
        $queryConfig = is_array($requirements['query'] ?? null) ? $requirements['query'] : [];
        $type = strtolower(trim((string) ($queryConfig['type'] ?? $requirements['query_type'] ?? 'none')));
        $via = strtolower(trim((string) ($queryConfig['via'] ?? $queryConfig['mode'] ?? 'agent')));

        return [
            'type' => $type !== '' ? $type : 'none',
            'via' => $via !== '' ? $via : 'agent',
            'config' => $queryConfig,
        ];
    }

    private function shouldRunHttpQuery(array $config): bool
    {
        return ($config['via'] ?? 'agent') === 'backend';
    }

    /**
     * @param array<string, mixed> $cached
     * @return array<string, mixed>
     */
    private function runHttpQuery(Instance $instance, ?PortBlock $portBlock, array $config): array
    {
        $context = $this->buildContext($instance, $portBlock, $config['config']);
        $adapter = $this->resolveAdapter($config['type']);
        $result = $adapter->query($instance, $context);
        $this->persistResult($instance, $result, 'backend');

        return $this->buildSnapshot($instance->getQueryStatusCache(), $instance->getQueryCheckedAt(), $config['type']);
    }

    private function isFresh(?\DateTimeImmutable $checkedAt): bool
    {
        if ($checkedAt === null) {
            return false;
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d seconds', self::CACHE_TTL_SECONDS));

        return $checkedAt >= $cutoff;
    }

    /**
     * @param array<string, mixed> $cache
     */
    private function isQueueCooldown(array $cache): bool
    {
        if (!is_string($cache['queued_at'] ?? null)) {
            return false;
        }

        try {
            $queuedAt = new \DateTimeImmutable((string) $cache['queued_at']);
        } catch (\Exception) {
            return false;
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d seconds', self::QUEUE_TTL_SECONDS));

        return $queuedAt >= $cutoff;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function queueQueryJob(Instance $instance, ?PortBlock $portBlock, array $config): void
    {
        $context = $this->buildContext($instance, $portBlock, $config['config']);
        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'query_type' => $config['type'],
            'host' => $context->getHost(),
            'game_port' => $context->getGamePort() !== null ? (string) $context->getGamePort() : null,
            'query_port' => $context->getQueryPort() !== null ? (string) $context->getQueryPort() : null,
            'rcon_port' => $context->getRconPort() !== null ? (string) $context->getRconPort() : null,
            'config' => $config['config'],
        ];

        $job = new Job('instance.query.check', $payload);
        $this->entityManager->persist($job);

        $cache = $instance->getQueryStatusCache();
        $cache['status'] = 'queued';
        $cache['queued_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $instance->setQueryStatusCache($cache);
        $this->entityManager->persist($instance);

        $this->auditLogger->log($instance->getCustomer(), 'instance.query.queued', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'query_type' => $config['type'],
        ]);

        $this->entityManager->flush();
    }

    private function buildContext(Instance $instance, ?PortBlock $portBlock, array $config): QueryContext
    {
        $host = $instance->getNode()->getLastHeartbeatIp();
        $requiredPorts = $instance->getTemplate()->getRequiredPorts();
        $gamePort = $this->resolvePort($portBlock, $requiredPorts, 'game');
        $queryPort = $this->resolvePort($portBlock, $requiredPorts, 'query');
        $rconPort = $this->resolvePort($portBlock, $requiredPorts, 'rcon');

        return new QueryContext($host, $gamePort, $queryPort, $rconPort, $config);
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     */
    private function resolvePort(?PortBlock $portBlock, array $requiredPorts, string $target): ?int
    {
        if ($portBlock === null) {
            return null;
        }

        $ports = $portBlock->getPorts();

        foreach ($requiredPorts as $index => $definition) {
            if (!isset($ports[$index])) {
                continue;
            }
            $name = strtolower((string) ($definition['name'] ?? ''));
            if ($name === $target) {
                return (int) $ports[$index];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $cache
     * @return array<string, mixed>
     */
    private function buildSnapshot(array $cache, ?\DateTimeImmutable $checkedAt, string $type): array
    {
        return [
            'available' => $type !== 'none',
            'status' => $cache['status'] ?? 'unknown',
            'players' => isset($cache['players']) && is_numeric($cache['players']) ? (int) $cache['players'] : null,
            'max_players' => isset($cache['max_players']) && is_numeric($cache['max_players']) ? (int) $cache['max_players'] : null,
            'checked_at' => $checkedAt?->format(DATE_ATOM),
            'queued_at' => $cache['queued_at'] ?? null,
        ];
    }

    private function persistResult(Instance $instance, QueryResult $result, string $source): void
    {
        $checkedAt = new \DateTimeImmutable();
        $instance->setQueryCheckedAt($checkedAt);
        $instance->setQueryStatusCache($result->toCacheArray($checkedAt, $source));
        $this->entityManager->persist($instance);
        $this->entityManager->flush();
    }

    private function resolveAdapter(string $type): QueryAdapterInterface
    {
        $adapters = [
            $this->httpQueryAdapter,
            $this->a2sQueryAdapter,
            $this->rconQueryAdapter,
            $this->noneQueryAdapter,
        ];

        foreach ($adapters as $adapter) {
            if ($adapter->supports($type)) {
                return $adapter;
            }
        }

        return $this->noneQueryAdapter;
    }
}
