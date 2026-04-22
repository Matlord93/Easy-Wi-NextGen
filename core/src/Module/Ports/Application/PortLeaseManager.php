<?php

declare(strict_types=1);

namespace App\Module\Ports\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;

class PortLeaseManager
{
    public function __construct(private readonly PortBlockRepository $portBlockRepository)
    {
    }

    /**
     * @return PortBlock[]
     */
    public function allocateBlocksInRange(PortPool $pool, User $customer, int $startPort, int $endPort, int $size): array
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

        if ($rangeSize % $size !== 0) {
            throw new \InvalidArgumentException('Port range must be divisible by the block size.');
        }

        $existingBlocks = $this->portBlockRepository->findByPool($pool);
        foreach ($existingBlocks as $block) {
            if ($block->getStartPort() <= $endPort && $block->getEndPort() >= $startPort) {
                throw new \RuntimeException('Port range overlaps an existing block.');
            }
        }

        $blocks = [];
        for ($current = $startPort; $current <= $endPort; $current += $size) {
            $blockEnd = $current + $size - 1;
            $blocks[] = new PortBlock($pool, $customer, $current, $blockEnd);
        }

        return $blocks;
    }

    public function allocateBlock(PortPool $pool, User $customer, int $size): PortBlock
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Port block size must be positive.');
        }

        $blocks = $this->portBlockRepository->findByPool($pool);
        $rangeStart = $pool->getStartPort();
        $rangeEnd = $pool->getEndPort();
        $step = max(1, $pool->getAllocationStep());

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

            if (!$overlap) {
                return new PortBlock($pool, $customer, $candidateStart, $candidateEnd);
            }
        }

        throw new \RuntimeException('No free ports left in pool.');
    }
}
