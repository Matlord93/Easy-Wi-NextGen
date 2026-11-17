<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Enum\InstanceDiskState;

final class InstanceDiskStateResolver
{
    public function resolveState(Instance $instance): InstanceDiskState
    {
        $limit = $instance->getDiskLimitBytes();
        if ($limit <= 0) {
            return InstanceDiskState::Ok;
        }

        $used = $instance->getDiskUsedBytes();
        $percent = ((float) $used / (float) $limit) * 100.0;

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

}
