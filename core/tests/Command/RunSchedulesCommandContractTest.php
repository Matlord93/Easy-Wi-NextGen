<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

final class RunSchedulesCommandContractTest extends TestCase
{
    public function testSchedulerContainsLifecycleSkipAndAuditCodes(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Module/Core/Command/RunSchedulesCommand.php');

        self::assertStringContainsString('lifecycle_action_in_progress', $source);
        self::assertStringContainsString('start_already_running', $source);
        self::assertStringContainsString('stop_already_stopped', $source);
        self::assertStringContainsString('restart_requires_running', $source);
        self::assertStringContainsString('instance.schedule.skipped', $source);
        self::assertStringContainsString('instance.backup.schedule_skipped', $source);
    }
}

