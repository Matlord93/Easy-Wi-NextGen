<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Monitoring;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Nodes\Application\Monitoring\InfrastructureMonitoringSettings;
use PHPUnit\Framework\TestCase;

final class InfrastructureMonitoringSettingsTest extends TestCase
{
    public function testMalformedStoredSettingsUseBoundedDefaults(): void
    {
        $settings = $this->createStub(AppSettingsServiceInterface::class);
        $settings->method('getSettings')->willReturn([AppSettingsService::KEY_INFRA_MONITORING => [
            'retention_days' => ['invalid'], 'proxmox_batch_size' => 10000,
            'hold_seconds' => '120', 'offline_seconds' => -1, 'enabled' => '0',
            'cpu_percent' => 0, 'alerts_enabled' => ['invalid'],
        ]]);
        $options = (new InfrastructureMonitoringSettings($settings))->get();

        self::assertFalse($options['enabled']);
        self::assertTrue($options['alerts_enabled']);
        self::assertSame(30, $options['retention_days']);
        self::assertSame(32, $options['proxmox_batch_size']);
        self::assertSame(120, $options['hold_seconds']);
        self::assertSame(180, $options['offline_seconds']);
        self::assertSame(90, $options['cpu_percent']);
    }

    public function testCollectorStateKeepsValidCursorsAndRejectsStructuredCounterValues(): void
    {
        $settings = $this->createStub(AppSettingsServiceInterface::class);
        $settings->method('getSettings')->willReturn([AppSettingsService::KEY_INFRA_MONITORING_STATE => [
            'vm_cursors' => [1 => '3', 2 => ['invalid'], 'unknown' => 1],
            'known_nodes' => [1 => ['node-a', '/invalid', ['nested'], 'node-b']],
            'cluster_cursor' => ['invalid'], 'last_minute' => '1234',
            'rollups_initialized' => false,
        ]]);
        $state = (new InfrastructureMonitoringSettings($settings))->state();

        self::assertSame([1 => 3], $state['vm_cursors']);
        self::assertSame([1 => ['node-a', 'node-b']], $state['known_nodes']);
        self::assertSame(0, $state['cluster_cursor']);
        self::assertSame(1234, $state['last_minute']);
        self::assertArrayNotHasKey('last_result', $state);
    }
}
