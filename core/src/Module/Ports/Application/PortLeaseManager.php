<?php

declare(strict_types=1);

namespace App\Module\Ports\Application;

use App\Entity\User;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;

final class PortLeaseManager
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
        $start = $pool->getStartPort();
        $end = $pool->getEndPort();

        foreach ($blocks as $block) {
            $blockStart = $block->getStartPort();
            $blockEnd = $block->getEndPort();
            if ($start + $size - 1 < $blockStart) {
                break;
            }
            if ($start <= $blockEnd) {
                $start = $blockEnd + 1;
            }
        }

        $blockEnd = $start + $size - 1;
        if ($blockEnd > $end) {
            throw new \RuntimeException('No free ports left in pool.');
        }

        return new PortBlock($pool, $customer, $start, $blockEnd);
    }
}
