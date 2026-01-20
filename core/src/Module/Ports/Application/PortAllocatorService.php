<?php

declare(strict_types=1);

namespace App\Module\Ports\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Domain\Entity\GameProfile;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Ports\Domain\Entity\PortAllocation;
use App\Module\Ports\Domain\Entity\PortPool;
use App\Module\Ports\Infrastructure\Repository\PortAllocationRepository;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class PortAllocatorService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly PortAllocationRepository $portAllocationRepository,
        private readonly AgentGameServerClient $agentClient,
    ) {
    }

    /**
     * @return PortAllocation[]
     */
    public function allocatePorts(Instance $instance, GameProfile $profile): array
    {
        $existing = $this->portAllocationRepository->findByInstance($instance);
        if ($existing !== []) {
            return $existing;
        }

        $roles = $profile->getPortRoles();
        $maxRetries = 5;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;
            $this->entityManager->beginTransaction();
            try {
                $allocations = $this->allocateRoles($instance, $roles);
                foreach ($allocations as $allocation) {
                    $this->entityManager->persist($allocation);
                }
                $this->entityManager->flush();
                $this->entityManager->commit();

                return $allocations;
            } catch (UniqueConstraintViolationException) {
                $this->entityManager->rollback();
                $this->entityManager->clear();
            } catch (\Throwable $exception) {
                $this->entityManager->rollback();
                throw $exception;
            }
        }

        throw new \RuntimeException('Failed to allocate ports after multiple attempts.');
    }

    /**
     * @param array<int, array<string, mixed>> $roles
     * @return PortAllocation[]
     */
    private function allocateRoles(Instance $instance, array $roles): array
    {
        $node = $instance->getNode();
        $allocations = [];
        $portMap = [];

        foreach ($roles as $role) {
            $roleKey = (string) ($role['role_key'] ?? '');
            $proto = (string) ($role['proto'] ?? 'udp');
            $purpose = $role['purpose'] ?? null;
            $strategy = (string) ($role['allocation_strategy'] ?? 'pool_single');
            $required = (bool) ($role['required'] ?? true);
            $poolTag = $role['pool_tag'] ?? null;

            if ($roleKey === '') {
                continue;
            }

            if ($strategy === 'derived_offset') {
                $baseRole = (string) ($role['derived_from'] ?? '');
                $offset = (int) ($role['offset'] ?? 0);
                if ($baseRole === '' || !isset($portMap[$baseRole])) {
                    if ($required) {
                        throw new \RuntimeException(sprintf('Derived port role %s missing base role.', $roleKey));
                    }
                    continue;
                }
                $port = $portMap[$baseRole] + $offset;
                $allocation = new PortAllocation(
                    $instance,
                    $node,
                    $roleKey,
                    $proto,
                    $port,
                    $strategy,
                    $required,
                    $poolTag,
                    is_string($purpose) ? $purpose : null,
                    $baseRole,
                    $offset,
                );
                $allocations[] = $allocation;
                $portMap[$roleKey] = $port;
                continue;
            }

            $port = $this->allocateFromPool($instance, $strategy, $proto, $poolTag, $role);
            if ($port === null) {
                if ($required) {
                    throw new \RuntimeException(sprintf('No free port found for role %s.', $roleKey));
                }
                continue;
            }

            $allocation = new PortAllocation(
                $instance,
                $node,
                $roleKey,
                $proto,
                $port,
                $strategy,
                $required,
                $poolTag,
                is_string($purpose) ? $purpose : null,
            );
            $allocation->setLastCheck(new \DateTimeImmutable(), true);
            $allocations[] = $allocation;
            $portMap[$roleKey] = $port;
        }

        return $allocations;
    }

    /**
     * @param array<string, mixed> $role
     */
    private function allocateFromPool(Instance $instance, string $strategy, string $proto, ?string $poolTag, array $role): ?int
    {
        $node = $instance->getNode();
        $pool = $poolTag !== null ? $this->portPoolRepository->findOneByNodeAndTag($node, $poolTag) : null;
        if ($pool === null) {
            return null;
        }

        return match ($strategy) {
            'pool_consecutive' => $this->allocateConsecutive($instance, $pool, $proto, (int) ($role['count'] ?? 1)),
            'fixed_default_or_pool' => $this->allocateFixedOrPool($instance, $pool, $proto, (int) ($role['default_port'] ?? 0)),
            default => $this->allocateSingle($instance, $pool, $proto),
        };
    }

    private function allocateSingle(Instance $instance, PortPool $pool, string $proto): ?int
    {
        $candidates = $this->candidatePorts($instance, $pool, $proto, 1, false);
        return $candidates[0] ?? null;
    }

    private function allocateConsecutive(Instance $instance, PortPool $pool, string $proto, int $count): ?int
    {
        $count = max(1, $count);
        $candidates = $this->candidatePorts($instance, $pool, $proto, $count, true);
        return $candidates[0] ?? null;
    }

    private function allocateFixedOrPool(Instance $instance, PortPool $pool, string $proto, int $defaultPort): ?int
    {
        if ($defaultPort > 0 && !$this->portAllocationRepository->isPortAllocated($pool->getNode(), $proto, $defaultPort)) {
            $checks = $this->agentClient->checkFreePorts($instance, [[
                'proto' => $proto,
                'port' => $defaultPort,
            ]]);
            $result = $checks[0]['free'] ?? false;
            if ($result === true) {
                return $defaultPort;
            }
        }

        return $this->allocateSingle($instance, $pool, $proto);
    }

    /**
     * @return int[]
     */
    private function candidatePorts(Instance $instance, PortPool $pool, string $proto, int $count, bool $consecutive): array
    {
        $start = $pool->getStartPort();
        $end = $pool->getEndPort();
        $used = $this->portAllocationRepository->findUsedPorts($pool->getNode(), $proto, $start, $end);
        $usedMap = array_fill_keys($used, true);

        if ($consecutive) {
            for ($port = $start; $port <= $end - $count + 1; $port++) {
                $range = range($port, $port + $count - 1);
                $blocked = false;
                foreach ($range as $candidate) {
                    if (isset($usedMap[$candidate])) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    continue;
                }
                $checks = array_map(static fn (int $value) => ['proto' => $proto, 'port' => $value], $range);
                $results = $this->agentClient->checkFreePorts($instance, $checks);
                if ($this->allFree($results)) {
                    return $range;
                }
            }

            return [];
        }

        for ($port = $start; $port <= $end; $port++) {
            if (isset($usedMap[$port])) {
                continue;
            }
            $checks = $this->agentClient->checkFreePorts($instance, [[
                'proto' => $proto,
                'port' => $port,
            ]]);
            if (($checks[0]['free'] ?? false) === true) {
                return [$port];
            }
        }

        return [];
    }

    /**
     * @param array<int, array{free: bool}> $results
     */
    private function allFree(array $results): bool
    {
        foreach ($results as $result) {
            if (($result['free'] ?? false) !== true) {
                return false;
            }
        }

        return $results !== [];
    }
}
