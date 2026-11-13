<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application;

use App\Module\Nodes\Application\VmGuestAgentReport;
use PHPUnit\Framework\TestCase;

final class VmGuestAgentReportTest extends TestCase
{
    public function testOnlyBoundedValidGuestFieldsAreReturned(): void
    {
        $report = VmGuestAgentReport::sanitize(['state' => 'reachable', 'observed_at' => '2026-11-13T12:00:00+00:00', 'version' => '9.2', 'hostname' => "bad\nhost", 'os' => str_repeat('x', 256), 'secret' => 'private']);
        self::assertSame(['state' => 'reachable', 'observed_at' => '2026-11-13T12:00:00+00:00', 'version' => '9.2', 'hostname' => null, 'os' => null, 'network' => ['state' => 'not_reported', 'interfaces' => [], 'limited' => false]], $report);
        self::assertNull(VmGuestAgentReport::text("\xff", 255));
        self::assertSame('2026-11-13T12:00:00+00:00', VmGuestAgentReport::sanitize(['observed_at' => '2026-11-13T12:00:00Z'])['observed_at']);
        self::assertNull(VmGuestAgentReport::sanitize(['observed_at' => '2026-02-30T12:00:00Z'])['observed_at']);
        self::assertNull(VmGuestAgentReport::sanitize(['state' => 'reachable', 'observed_at' => 'tomorrow'])['observed_at']);
    }

    public function testFailureCannotClaimGuestFields(): void
    {
        $report = VmGuestAgentReport::observed('unreachable', ['version' => 'stale-version', 'hostname' => 'old-host']);
        self::assertNull($report['version']);
        self::assertNull($report['hostname']);
        self::assertNotNull($report['observed_at']);
        self::assertSame('unavailable', VmGuestAgentReport::sanitize(['state' => 'injected'])['state']);
    }
}
