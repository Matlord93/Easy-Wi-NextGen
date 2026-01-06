<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PortBlock;
use App\Entity\PortPool;
use App\Entity\User;
use App\Repository\PortBlockRepository;

final class PortLeaseManager
{
    public function __construct(private readonly PortBlockRepository $portBlockRepository)
    {
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
