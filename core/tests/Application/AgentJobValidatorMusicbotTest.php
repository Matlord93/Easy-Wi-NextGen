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

    public function testMusicbotTeamspeakBackendInstallRequiresLocalBackendFields(): void
    {
        $validator = new AgentJobValidator();

        $payload = [
            'node_id' => 'agent-1',
            'backend_type' => 'client_library',
            'backend_path' => '/usr/local/bin/easywi-teamspeak-client',
            'binary_path' => '/usr/local/bin/easywi-teamspeak-client',
            'library_path' => '/opt/easywi/musicbot/teamspeak-client/libts3client.so',
            'opus_library_path' => '/opt/easywi/musicbot/teamspeak-client/libopus.so',
            'install_path' => '/opt/easywi/musicbot/teamspeak-client/',
            'requested_by' => '1',
            'dry_run' => false,
        ];

        self::assertSame([], $validator->validate('musicbot.teamspeak_backend.install', $payload));
        self::assertContains('Missing required field: library_path', $validator->validate('musicbot.teamspeak_backend.install', array_diff_key($payload, ['library_path' => true])));
        self::assertArrayNotHasKey('server_password', $payload);
        self::assertArrayNotHasKey('channel_password', $payload);
    }

    public function testMusicbotTeamspeakOfficialClientInstallRequiresConfirmationPayload(): void
    {
        $validator = new AgentJobValidator();
        $payload = [
            'node_id' => 'agent-1',
            'version' => '3.6.2',
            'download_url' => 'https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run',
            'expected_sha256' => '',
            'install_path' => '/opt/easywi/musicbot/teamspeak-client/official-client/',
            'requested_by' => '1',
            'accepted_license_confirmation' => true,
        ];

        self::assertSame([], $validator->validate('musicbot.teamspeak_backend.install_official_client', $payload));
        self::assertContains('Missing required field: accepted_license_confirmation', $validator->validate('musicbot.teamspeak_backend.install_official_client', array_diff_key($payload, ['accepted_license_confirmation' => true])));
        self::assertArrayNotHasKey('server_password', $payload);
        self::assertArrayNotHasKey('channel_password', $payload);
    }
}
