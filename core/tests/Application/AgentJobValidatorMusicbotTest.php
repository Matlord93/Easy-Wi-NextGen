<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\AgentOrchestrator\Application\AgentJobValidator;
use PHPUnit\Framework\TestCase;

final class AgentJobValidatorMusicbotTest extends TestCase
{
    public function testMusicbotInstallRequiresControlPlaneFields(): void
    {
        $validator = new AgentJobValidator();

        self::assertSame([], $validator->validate('musicbot.install', [
            'instance_id' => '42',
            'customer_id' => '7',
            'node_id' => 'agent-1',
            'service_name' => 'musicbot-demo',
            'install_path' => '/var/lib/easywi/musicbot/musicbot-demo',
        ]));
        self::assertContains('Missing required field: install_path', $validator->validate('musicbot.install', [
            'instance_id' => '42',
            'customer_id' => '7',
            'node_id' => 'agent-1',
            'service_name' => 'musicbot-demo',
        ]));
    }

    public function testMusicbotServiceStatusPlaybackAndConnectionValidation(): void
    {
        $validator = new AgentJobValidator();

        self::assertSame([], $validator->validate('musicbot.service.action', [
            'instance_id' => '42',
            'action' => 'start',
            'service_name' => 'musicbot-demo',
        ]));
        self::assertContains('Missing required field: action', $validator->validate('musicbot.playback.action', [
            'instance_id' => '42',
        ]));
        self::assertContains('Missing required field: platform', $validator->validate('musicbot.connection.test', [
            'instance_id' => '42',
        ]));
        self::assertSame([], $validator->validate('musicbot.status', [
            'instance_id' => '42',
            'service_name' => 'musicbot-demo',
        ]));
    }

    public function testMusicbotUpdateAndRepairRequireInstallPath(): void
    {
        $validator = new AgentJobValidator();

        foreach (['musicbot.update', 'musicbot.repair'] as $type) {
            self::assertSame([], $validator->validate($type, [
                'instance_id' => '42',
                'service_name' => 'musicbot-demo',
                'install_path' => '/var/lib/easywi/musicbot/musicbot-demo',
            ]));

            self::assertContains('Missing required field: install_path', $validator->validate($type, [
                'instance_id' => '42',
                'service_name' => 'musicbot-demo',
            ]));
        }
    }

    public function testMusicbotPluginJobsRequirePluginId(): void
    {
        $validator = new AgentJobValidator();

        self::assertSame([], $validator->validate('musicbot.plugin.install', [
            'instance_id' => '42',
            'plugin_id' => 'metadata.example',
        ]));
        self::assertContains('Missing required field: plugin_id', $validator->validate('musicbot.plugin.remove', [
            'instance_id' => '42',
        ]));
    }
}
