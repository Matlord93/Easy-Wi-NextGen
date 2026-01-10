<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Instance;
use App\Enum\InstanceDiskState;

final class InstanceDiskStateResolver
{
    public function resolveState(Instance $instance): InstanceDiskState
    {
        $limit = $instance->getDiskLimitBytes();
        if ($limit <= 0) {
            return InstanceDiskState::Ok;
        }

        $used = $instance->getDiskUsedBytes();
        $percent = ($used / $limit) * 100;

        $node = $instance->getNode();
        $warningPercent = $node->getDiskWarningPercent();
        $hardBlockPercent = $node->getDiskHardBlockPercent();

        if ($percent >= $hardBlockPercent) {
            return InstanceDiskState::HardBlock;
        }
        if ($percent >= 100) {
            return InstanceDiskState::OverLimit;
        }
        if ($percent >= $warningPercent) {
            return InstanceDiskState::Warning;
        }

        return InstanceDiskState::Ok;
    }

    public function resolvePercent(Instance $instance): float
    {
        $limit = $instance->getDiskLimitBytes();
        if ($limit <= 0) {
            return 0.0;
        }

        return ($instance->getDiskUsedBytes() / $limit) * 100;
    }
}
