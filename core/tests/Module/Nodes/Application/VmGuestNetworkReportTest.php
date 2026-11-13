<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Application;

use App\Module\Nodes\Application\VmGuestAgentReport;
use App\Module\Nodes\Application\VmGuestNetworkReport;
use PHPUnit\Framework\TestCase;

final class VmGuestNetworkReportTest extends TestCase
{
    public function testProtocolCasesMatchTheAgentContract(): void
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__, 5).'/agent/cmd/agent/testdata/vm-guest-network.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            self::assertSame($case['expected'], VmGuestNetworkReport::fromQga($case['input']), $case['name']);
        }
    }

    public function testOversizedListsAreBoundedAndMarked(): void
    {
        $rows = [];
        for ($i = 0; $i < 17; ++$i) {
            $ips = [];
            for ($j = 0; $j < 17; ++$j) {
                $ips[] = ['ip-address-type' => 'ipv4', 'ip-address' => '192.0.2.'.($j + 1), 'prefix' => 24];
            }
            $rows[] = ['name' => 'nic'.$i, 'ip-addresses' => $ips];
        }
        $network = VmGuestNetworkReport::fromQga($rows);
        self::assertTrue($network['limited']);
        self::assertCount(16, $network['interfaces']);
        foreach ($network['interfaces'] as $row) {
            self::assertCount(16, $row['addresses']);
        }
    }

    public function testCanonicalAgentResultsAreValidatedAgainAndFailuresClearAddresses(): void
    {
        $network = ['state' => 'available', 'interfaces' => [['name' => 'eth0', 'mac' => 'aa:bb:cc:dd:ee:ff', 'addresses' => [['family' => 'ipv4', 'address' => '192.0.2.1', 'prefix' => 24, 'secret' => 'private']]], ['name' => "bad\nname"]], 'raw_host_path' => '/private'];
        $safe = VmGuestAgentReport::observed('reachable', ['network' => $network]);
        self::assertTrue($safe['network']['limited']);
        self::assertCount(1, $safe['network']['interfaces']);
        self::assertStringNotContainsString('private', json_encode($safe));
        self::assertSame('not_reported', VmGuestAgentReport::observed('reachable')['network']['state']);
        $failed = VmGuestAgentReport::observed('unreachable', ['network' => $network]);
        self::assertSame('unavailable', $failed['network']['state']);
        self::assertSame([], $failed['network']['interfaces']);
    }
}
