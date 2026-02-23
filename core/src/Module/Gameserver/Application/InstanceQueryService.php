<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Gameserver\Application\Query\A2sQueryAdapter;
use App\Module\Gameserver\Application\Query\HttpQueryAdapter;
use App\Module\Gameserver\Application\Query\InstanceQueryResolver;
use App\Module\Gameserver\Application\Query\InstanceQuerySpec;
use App\Module\Gameserver\Application\Query\InvalidInstanceQueryConfiguration;
use App\Module\Gameserver\Application\Query\NoneQueryAdapter;
use App\Module\Gameserver\Application\Query\QueryAdapterInterface;
use App\Module\Gameserver\Application\Query\QueryContext;
use App\Module\Gameserver\Application\Query\QueryResult;
use App\Module\Gameserver\Application\Query\QueryResultNormalizer;
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
        private readonly InstanceQueryResolver $instanceQueryResolver,
    ) {
    }

    /**
     * @throws InvalidInstanceQueryConfiguration
     */
    public function resolveQuerySpec(Instance $instance, ?PortBlock $portBlock): InstanceQuerySpec
    {
        return $this->instanceQueryResolver->resolve($instance, $portBlock);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(Instance $instance, ?PortBlock $portBlock, bool $queueIfStale = false): array
    {
        try {
            $spec = $this->resolveQuerySpec($instance, $portBlock);
        } catch (InvalidInstanceQueryConfiguration $exception) {
            return [
                'available' => false,
                'status' => 'error',
                'players' => null,
                'max_players' => null,
                'map' => null,
                'error' => $exception->getMessage(),
                'result' => QueryResultNormalizer::build(null, null, null, $exception->getMessage(), [], []),
                'checked_at' => null,
                'queued_at' => null,
            ];
        }

        $type = $spec->isSupported() ? (string) $spec->getType() : 'none';
        $config = [
            'type' => $type,
            'via' => strtolower((string) ($spec->getExtra()['via'] ?? 'agent')),
            'config' => ['timeout_ms' => $spec->getTimeoutMs()],
        ];
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

    private function shouldRunHttpQuery(array $config): bool
    {
        return ($config['via'] ?? 'agent') === 'backend';
    }

    /**
     * @return array<string, mixed>
     */
    private function runHttpQuery(Instance $instance, ?PortBlock $portBlock, array $config): array
    {
        $spec = $this->resolveQuerySpec($instance, $portBlock);
        $context = $this->buildContext($instance, $portBlock, $spec, $config['config']);
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
        $spec = $this->resolveQuerySpec($instance, $portBlock);
        $context = $this->buildContext($instance, $portBlock, $spec, $config['config']);
        $fallbackPorts = $this->resolveFallbackQueryPorts($instance, $portBlock, $context->getQueryPort(), $context->getGamePort());

        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'query_type' => $config['type'],
            'protocol' => $config['type'],
            'host' => $context->getHost(),
            'ip' => $context->getHost(),
            'timeout_ms' => (int) ($config['config']['timeout_ms'] ?? 4000),
            'network_mode' => (string) ($spec->getExtra()['network_mode'] ?? 'isolated'),
            'resolved_host_source' => (string) ($spec->getExtra()['resolved_host_source'] ?? 'instance_ip'),
            'port' => $context->getGamePort() !== null ? (string) $context->getGamePort() : null,
            'game_port' => $context->getGamePort() !== null ? (string) $context->getGamePort() : null,
            'query_port' => $context->getQueryPort() !== null ? (string) $context->getQueryPort() : null,
            'rcon_port' => $context->getRconPort() !== null ? (string) $context->getRconPort() : null,
            'fallback_query_ports' => $fallbackPorts,
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

    private function buildContext(Instance $instance, ?PortBlock $portBlock, InstanceQuerySpec $spec, array $config): QueryContext
    {
        $host = $spec->getHost();
        $queryPort = $spec->getPort();
        $gamePort = $this->resolveGamePort($instance, $portBlock, $queryPort);
        $rconPort = null;

        return new QueryContext($host, $gamePort, $queryPort, $rconPort, $config + ['query_type' => $spec->getType()]);
    }

    private function resolveGamePort(Instance $instance, ?PortBlock $portBlock, ?int $fallback): ?int
    {
        $setupVars = $instance->getSetupVars();
        if (is_numeric($setupVars['GAME_PORT'] ?? null)) {
            return (int) $setupVars['GAME_PORT'];
        }

        $requiredPorts = $instance->getTemplate()->getRequiredPorts();
        if ($portBlock !== null) {
            $ports = $portBlock->getPorts();
            foreach ($requiredPorts as $index => $definition) {
                if (!isset($ports[$index])) {
                    continue;
                }

                $name = strtolower((string) ($definition['name'] ?? ''));
                if ($name === 'game' || str_contains($name, 'game')) {
                    return (int) $ports[$index];
                }
            }
        }

        foreach (['PORT', 'SERVER_PORT'] as $setupPortKey) {
            if (is_numeric($setupVars[$setupPortKey] ?? null)) {
                return (int) $setupVars[$setupPortKey];
            }
        }

        if ($instance->getAssignedPort() !== null) {
            return $instance->getAssignedPort();
        }

        return $fallback;
    }

    /**
     * @return list<int>
     */
    private function resolveFallbackQueryPorts(Instance $instance, ?PortBlock $portBlock, ?int $queryPort, ?int $gamePort): array
    {
        $setupVars = $instance->getSetupVars();
        $requiredPorts = $instance->getTemplate()->getRequiredPorts();
        $portCandidates = [];

        if ($gamePort !== null) {
            $portCandidates[] = $gamePort;
        }

        foreach (['GAME_PORT', 'PORT', 'SERVER_PORT', 'QUERY_PORT', 'STEAM_QUERY_PORT', 'SV_QUERYPORT'] as $setupPortKey) {
            if (is_numeric($setupVars[$setupPortKey] ?? null)) {
                $portCandidates[] = (int) $setupVars[$setupPortKey];
            }
        }

        if ($portBlock !== null) {
            $ports = $portBlock->getPorts();
            foreach ($requiredPorts as $index => $definition) {
                if (!isset($ports[$index])) {
                    continue;
                }

                $name = strtolower((string) ($definition['name'] ?? ''));
                if ($name === 'game' || str_contains($name, 'game') || $name === 'query' || str_contains($name, 'query')) {
                    $portCandidates[] = (int) $ports[$index];
                }
            }
        }

        if ($instance->getAssignedPort() !== null) {
            $portCandidates[] = $instance->getAssignedPort();
        }

        $fallbackPorts = [];
        foreach ($portCandidates as $port) {
            if ($port < 1 || $port > 65535) {
                continue;
            }
            if ($queryPort !== null && $port === $queryPort) {
                continue;
            }
            if (in_array($port, $fallbackPorts, true)) {
                continue;
            }

            $fallbackPorts[] = $port;
        }

        return $fallbackPorts;
    }

    /**
     * @param array<string, mixed> $cache
     * @return array<string, mixed>
     */
    private function buildSnapshot(array $cache, ?\DateTimeImmutable $checkedAt, string $type): array
    {
        $normalized = is_array($cache['result'] ?? null)
            ? $cache['result']
            : QueryResultNormalizer::fromLegacyCache($cache, $type);

        return [
            'available' => $type !== 'none',
            'status' => $cache['status'] ?? 'unknown',
            'players' => isset($cache['players']) && is_numeric($cache['players']) ? (int) $cache['players'] : null,
            'max_players' => isset($cache['max_players']) && is_numeric($cache['max_players']) ? (int) $cache['max_players'] : null,
            'map' => is_string($cache['map'] ?? null) ? (string) $cache['map'] : null,
            'error' => is_string($cache['message'] ?? null) ? (string) $cache['message'] : null,
            'result' => $normalized,
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
