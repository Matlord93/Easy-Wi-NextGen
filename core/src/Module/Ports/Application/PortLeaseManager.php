<?php

declare(strict_types=1);

namespace App\Module\Ports\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;

class PortLeaseManager
{
    public function __construct(
        private readonly PortBlockRepository $portBlockRepository,
        private readonly NodePortAvailability $availability,
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
    ) {
    }

    /**
     * @return PortBlock[]
     */
    public function allocateBlocksInRange(PortPool $pool, User $customer, int $startPort, int $endPort, int $size): array
    {
        return $this->reserve($pool, fn (): array => $this->buildBlocksInRange($pool, $customer, $startPort, $endPort, $size));
    }

    private function buildBlocksInRange(PortPool $pool, User $customer, int $startPort, int $endPort, int $size): array
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Port block size must be positive.');
        }

        if ($startPort <= 0 || $endPort <= 0 || $startPort > $endPort) {
            throw new \InvalidArgumentException('Port range is invalid.');
        }

        if ($startPort < $pool->getStartPort() || $endPort > $pool->getEndPort()) {
            throw new \InvalidArgumentException('Port range must stay within the pool.');
        }

        $rangeSize = $endPort - $startPort + 1;
        if ($rangeSize < $size) {
            throw new \InvalidArgumentException('Port range is smaller than the requested block size.');
        }

        if (0 !== $rangeSize % $size) {
            throw new \InvalidArgumentException('Port range must be divisible by the block size.');
        }

        $existingBlocks = $this->portBlockRepository->findByNode($pool->getNode());
        foreach ($existingBlocks as $block) {
            if ($block->getStartPort() <= $endPort && $block->getEndPort() >= $startPort) {
                throw new \RuntimeException('Port range overlaps an existing block.');
            }
        }

        $this->assertPortsFree($pool, range($startPort, $endPort));
        $blocks = [];
        for ($current = $startPort; $current <= $endPort; $current += $size) {
            $blockEnd = $current + $size - 1;
            $blocks[] = new PortBlock($pool, $customer, $current, $blockEnd);
        }

        return $blocks;
    }

    public function allocateBlock(PortPool $pool, User $customer, int $size): PortBlock
    {
        return $this->reserve($pool, fn (): array => [$this->buildBlock($pool, $customer, $size)])[0];
    }

    private function buildBlock(PortPool $pool, User $customer, int $size): PortBlock
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Port block size must be positive.');
        }

        $blocks = $this->portBlockRepository->findByNode($pool->getNode());
        $rangeStart = $pool->getStartPort();
        $rangeEnd = $pool->getEndPort();
        $step = max(1, $pool->getAllocationStep());

        $allocated = $this->allocatedPorts($pool);
        $probed = [];
        $busy = [];
        $deadline = microtime(true) + 20;
        for ($candidateStart = $rangeStart; $candidateStart <= $rangeEnd; $candidateStart += $step) {
            $candidateEnd = $candidateStart + $size - 1;
            if ($candidateEnd > $rangeEnd) {
                break;
            }

            $overlap = false;
            foreach ($blocks as $block) {
                if ($candidateStart <= $block->getEndPort() && $candidateEnd >= $block->getStartPort()) {
                    $overlap = true;
                    break;
                }
            }

            foreach (range($candidateStart, $candidateEnd) as $port) {
                if (isset($allocated[$port])) {
                    $overlap = true;
                    break;
                }
            }
            if (!$overlap) {
                $needed = array_values(array_filter(range($candidateStart, $candidateEnd), static fn (int $port): bool => !isset($probed[$port])));
                if ([] !== $needed) {
                    if (microtime(true) > $deadline) {
                        throw new PortCheckUnavailable('Portprüfung dauert zu lange. Es wurden keine Ports vergeben.');
                    }
                    $batch = range($candidateStart, min($rangeEnd, max($candidateEnd, $candidateStart + 127)));
                    foreach ($this->availability->blockedPorts($pool->getNode(), $batch) as $port) {
                        $busy[$port] = true;
                    }
                    foreach ($batch as $port) {
                        $probed[$port] = true;
                    }
                }
                foreach (range($candidateStart, $candidateEnd) as $port) {
                    if (isset($busy[$port])) {
                        continue 2;
                    }
                }

                return new PortBlock($pool, $customer, $candidateStart, $candidateEnd);
            }
        }

        throw new \RuntimeException('No free ports left in pool.');
    }

    private function reserve(PortPool $pool, callable $allocate): array
    {
        if (!$pool->isEnabled()) {
            throw new \RuntimeException('Portbereich ist deaktiviert.');
        }

        return $this->em->getConnection()->transactional(function () use ($pool, $allocate): array {
            $this->em->lock($pool->getNode(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $blocks = $allocate();
            foreach ($blocks as $block) {
                $this->em->persist($block);
            }
            $this->em->flush();

            return $blocks;
        });
    }

    private function allocatedPorts(PortPool $pool): array
    {
        $result = [];
        foreach ($this->em->getRepository(\App\Module\Ports\Domain\Entity\PortAllocation::class)->findBy(['node' => $pool->getNode()]) as $allocation) {
            $result[$allocation->getPort()] = true;
        }

        return $result;
    }

    private function assertPortsFree(PortPool $pool, array $ports): void
    {
        $allocated = $this->allocatedPorts($pool);
        foreach ($ports as $port) {
            if (isset($allocated[$port])) {
                throw new \RuntimeException('Port ist bereits im Panel reserviert: '.$port);
            }
        }
        foreach (array_chunk($ports, 128) as $batch) {
            if ([] !== $this->availability->blockedPorts($pool->getNode(), $batch)) {
                throw new \RuntimeException('Mindestens ein Port ist bereits auf dem Node belegt.');
            }
        }
    }

    public function assertAssignable(PortBlock $block, \App\Module\Core\Domain\Entity\Template $template): void
    {
        $pools = $this->em->getRepository(PortPool::class)->findBy(['node' => $block->getPool()->getNode()]);
        if (!GamePortPoolPolicy::allows($block->getPool(), $template, $pools)) {
            throw new \RuntimeException('Der Portbereich passt nicht zum gewählten Spiel oder ist deaktiviert.');
        }
        foreach ($this->portBlockRepository->findByNode($block->getPool()->getNode()) as $other) {
            if ($other->getId() !== $block->getId() && $other->getStartPort() <= $block->getEndPort() && $other->getEndPort() >= $block->getStartPort()) {
                throw new \RuntimeException('Portblock überschneidet sich mit einer bestehenden Reservierung.');
            }
        }
        $this->assertPortsFree($block->getPool(), $block->getPorts());
    }
}
