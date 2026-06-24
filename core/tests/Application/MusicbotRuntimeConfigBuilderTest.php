<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotRuntimeConfigBuilder;
use App\Module\Musicbot\Application\MusicbotSecretConfigService;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;
use App\Module\Musicbot\Domain\Entity\MusicbotStreamSettings;
use App\Module\Musicbot\Domain\Entity\MusicbotTeamspeakBackendConfig;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Module\Musicbot\Domain\Enum\MusicbotStreamAccessMode;
use App\Module\Musicbot\Domain\Enum\MusicbotTeamspeakBackendStatus;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotStreamSettingsRepository;
use App\Repository\MusicbotTeamspeakBackendConfigRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests that MusicbotRuntimeConfigBuilder assembles the agent config payload
 * entirely from DB entities (no ENV lookups), and that the sanitized variant
 * never contains plaintext secrets.
 */
final class MusicbotRuntimeConfigBuilderTest extends TestCase
{
    private MusicbotSecretConfigService $secretService;
    private MusicbotInstance $instance;

    protected function setUp(): void
    {
        $this->secretService = new MusicbotSecretConfigService(new SecretsCrypto('test-app-secret'));

        $customer = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('agent-uuid-1', ['token' => 'hash'], 'Test Agent');
        $this->instance = new MusicbotInstance(
            $customer,
            $agent,
            'Test Bot',
            'musicbot-test-abc123',
            '/var/lib/easywi/musicbot/musicbot-test-abc123',
        );
    }

    public function testBuildIncludesDiscordConfigWithDecryptedToken(): void
    {
        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, [
            'guild_id' => 'gid-1',
            'voice_channel_id' => 'vc-1',
            'text_channel_id' => 'tc-1',
            'application_id' => 'app-1',
            'command_mode' => 'slash',
            'slash_commands_enabled' => true,
            'reconnect_policy' => 'manual',
        ], $this->secretService->encrypt(['bot_token' => 'tok-abc']));
        $conn->setEnabled(true);

        $builder = $this->buildService([$conn], null, []);
        $config = $builder->build($this->instance);

        self::assertTrue($config['discord']['enabled']);
        self::assertSame('gid-1', $config['discord']['guild_id']);
        self::assertSame('vc-1', $config['discord']['voice_channel_id']);
        self::assertSame('tc-1', $config['discord']['text_channel_id']);
        self::assertSame('tok-abc', $config['discord']['bot_token']);
    }

    public function testBuildIncludesTeamspeakConfigWithDecryptedPasswords(): void
    {
        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [
            'host' => 'ts3.example.com',
            'port' => 9987,
            'nickname' => 'MusicBot',
            'channel_id' => '5',
            'profile' => 'ts3',
            'backend' => 'ts3_client_compatible',
            'backend_type' => 'native_sdk',
            'backend_path' => '/opt/ts3/backend.so',
            'identity_path' => '/opt/ts3/identity.ini',
            'library_path' => '/opt/ts3/libts3sdk.so',
            'binary_path' => '/opt/ts3/ts3client',
            'command_prefix' => '!',
            'commands_enabled' => true,
            'events_enabled' => true,
            'allowed_server_groups' => ['10', '11'],
            'dj_server_groups' => ['20'],
            'admin_server_groups' => ['3'],
        ], $this->secretService->encrypt([
            'server_password' => 'srv-pw',
            'channel_password' => 'chan-pw',
        ]));
        $conn->setEnabled(true);

        $builder = $this->buildService([$conn], null, []);
        $config = $builder->build($this->instance);

        self::assertTrue($config['teamspeak']['enabled']);
        self::assertSame('ts3.example.com', $config['teamspeak']['host']);
        self::assertSame(9987, $config['teamspeak']['port']);
        self::assertSame('MusicBot', $config['teamspeak']['nickname']);
        self::assertSame('5', $config['teamspeak']['channel_id']);
        self::assertSame('/opt/ts3/libts3sdk.so', $config['teamspeak']['library_path']);
        self::assertSame('/opt/ts3/ts3client', $config['teamspeak']['binary_path']);
        self::assertSame('srv-pw', $config['teamspeak']['server_password']);
        self::assertSame('chan-pw', $config['teamspeak']['channel_password']);
        self::assertSame(['10', '11'], $config['teamspeak']['allowed_server_groups']);
    }


    public function testTeamspeakRuntimeConfigMergesReadyAdminClientLibraryBackendPaths(): void
    {
        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [
            'host' => 'panel.florian-stern.de',
            'port' => 9987,
            'profile' => 'ts3',
            'backend_type' => 'client_library',
            'backend_path' => '',
            'library_path' => '',
            'opus_library_path' => '',
        ], []);
        $conn->setEnabled(true);

        $backendConfig = new MusicbotTeamspeakBackendConfig($this->instance->getNode());
        $backendConfig->setBackendType('client_library');
        $backendConfig->setBackendPath('/usr/local/bin/easywi-teamspeak-client');
        $backendConfig->setBinaryPath('/usr/local/bin/easywi-teamspeak-client');
        $backendConfig->setLibraryPath('/opt/easywi/musicbot/teamspeak-client/libts3client.so');
        $backendConfig->setOpusLibraryPath('/usr/lib/x86_64-linux-gnu/libopus.so.0');
        $backendConfig->setStatus(MusicbotTeamspeakBackendStatus::Ready);

        $builder = $this->buildService([$conn], null, [], $backendConfig);
        $config = $builder->build($this->instance);

        self::assertSame('panel.florian-stern.de', $config['teamspeak']['host']);
        self::assertSame(9987, $config['teamspeak']['port']);
        self::assertSame('client_library', $config['teamspeak']['backend_type']);
        self::assertSame('/usr/local/bin/easywi-teamspeak-client', $config['teamspeak']['backend_path']);
        self::assertSame('/opt/easywi/musicbot/teamspeak-client/libts3client.so', $config['teamspeak']['library_path']);
        self::assertSame('/usr/lib/x86_64-linux-gnu/libopus.so.0', $config['teamspeak']['opus_library_path']);
    }

    public function testExternalClientBridgeRuntimeConfigEmitsBridgePathAndNotLibraryPath(): void
    {
        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [
            'host' => 'ts3.example.com',
            'port' => 9987,
            'profile' => 'ts3',
            'backend_type' => 'external_client_bridge',
        ], []);
        $conn->setEnabled(true);

        $backendConfig = new MusicbotTeamspeakBackendConfig($this->instance->getNode());
        $backendConfig->setBackendType('external_client_bridge');
        $backendConfig->setBridgePath('/usr/local/bin/easywi-teamspeak-bridge');
        $backendConfig->setOfficialClientBinaryPath('/opt/easywi/musicbot/teamspeak-client/official-client/ts3client_linux_amd64');
        $backendConfig->setOfficialClientRunscriptPath('/opt/easywi/musicbot/teamspeak-client/official-client/ts3client_runscript.sh');
        $backendConfig->setAudioBackend('pulseaudio_virtual_source');
        $backendConfig->setStatus(MusicbotTeamspeakBackendStatus::ExternalBridgeReady);

        $builder = $this->buildService([$conn], null, [], $backendConfig);
        $config = $builder->build($this->instance);

        self::assertSame('external_client_bridge', $config['teamspeak']['backend_type']);
        self::assertSame('/usr/local/bin/easywi-teamspeak-bridge', $config['teamspeak']['bridge_path']);
        self::assertSame('/opt/easywi/musicbot/teamspeak-client/official-client/ts3client_linux_amd64', $config['teamspeak']['client_binary_path']);
        self::assertSame('pulseaudio_virtual_source', $config['teamspeak']['audio_backend']);
        self::assertTrue($config['teamspeak']['autoconnect']);
        // instance_path and runtime_dir must be set so the bridge uses a persistent dir.
        self::assertSame('/var/lib/easywi/musicbot/musicbot-test-abc123', $config['teamspeak']['instance_path']);
        self::assertSame('/var/lib/easywi/musicbot/musicbot-test-abc123/runtime/teamspeak-bridge', $config['teamspeak']['runtime_dir']);
        // client_query_host must default to 127.0.0.1 so the bridge binds on loopback.
        self::assertSame('127.0.0.1', $config['teamspeak']['client_query_host']);
        // library_path and opus_library_path must NOT be emitted for external_client_bridge
        self::assertArrayNotHasKey('library_path', $config['teamspeak']);
        self::assertArrayNotHasKey('opus_library_path', $config['teamspeak']);
    }

    public function testExternalClientBridgeNotReadyYieldsClientBackendRequired(): void
    {
        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [
            'host' => 'ts3.example.com',
            'port' => 9987,
            'profile' => 'ts3',
            'backend_type' => 'external_client_bridge',
        ], []);
        $conn->setEnabled(true);

        $backendConfig = new MusicbotTeamspeakBackendConfig($this->instance->getNode());
        $backendConfig->setBackendType('external_client_bridge');
        $backendConfig->setStatus(MusicbotTeamspeakBackendStatus::XvfbMissing);

        $builder = $this->buildService([$conn], null, [], $backendConfig);
        $config = $builder->build($this->instance);

        self::assertSame('client_backend_required', $config['teamspeak']['backend_status'], 'Non-ready bridge status must not forward incomplete config to runtime');
    }

    public function testPlaceholderIsNotTreatedAsReadyAndClientLibraryRequiresAdminBackend(): void
    {
        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [
            'host' => 'ts3.example.com',
            'port' => 9987,
            'profile' => 'ts3',
            'backend_type' => 'placeholder',
        ], []);
        $conn->setEnabled(true);

        $config = $this->buildService([$conn], null, [])->build($this->instance);

        self::assertSame('placeholder', $config['teamspeak']['backend_type']);
        self::assertSame('', $config['teamspeak']['backend_path']);
        self::assertSame('client_backend_required', $config['teamspeak']['backend_status']);

        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [
            'host' => 'ts3.example.com',
            'port' => 9987,
            'profile' => 'ts3',
            'backend_type' => 'client_library',
        ], []);
        $conn->setEnabled(true);

        $config = $this->buildService([$conn], null, [])->build($this->instance);

        self::assertSame('client_library', $config['teamspeak']['backend_type']);
        self::assertSame('', $config['teamspeak']['backend_path']);
        self::assertSame('client_backend_required', $config['teamspeak']['backend_status']);
    }

    public function testPlaceholderBackendTypeIsAutoPromotedToExternalClientBridgeWhenNodeBackendIsReady(): void
    {
        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [
            'host' => 'ts3.example.com',
            'port' => 9987,
            'profile' => 'ts3',
            'backend_type' => 'placeholder',
        ], []);
        $conn->setEnabled(true);

        $backendConfig = new MusicbotTeamspeakBackendConfig($this->instance->getNode());
        $backendConfig->setBackendType('external_client_bridge');
        $backendConfig->setBridgePath('/usr/local/bin/easywi-teamspeak-bridge');
        $backendConfig->setOfficialClientBinaryPath('/opt/easywi/musicbot/teamspeak-client/official-client/ts3client_linux_amd64');
        $backendConfig->setAudioBackend('pulseaudio_virtual_source');
        $backendConfig->setStatus(MusicbotTeamspeakBackendStatus::ExternalBridgeReady);

        $builder = $this->buildService([$conn], null, [], $backendConfig);
        $config = $builder->build($this->instance);

        self::assertSame('external_client_bridge', $config['teamspeak']['backend_type'],
            'placeholder backend_type must be promoted to external_client_bridge when node backend is ready');
        self::assertSame('/usr/local/bin/easywi-teamspeak-bridge', $config['teamspeak']['bridge_path']);
        self::assertSame('127.0.0.1', $config['teamspeak']['client_query_host']);
    }

    public function testBuildReturnsFalseEnabledForDisabledConnection(): void
    {
        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, [], []);
        $conn->setEnabled(false);

        $builder = $this->buildService([$conn], null, []);
        $config = $builder->build($this->instance);

        self::assertFalse($config['discord']['enabled']);
    }

    public function testBuildReturnsFalseEnabledWhenNoConnection(): void
    {
        $builder = $this->buildService([], null, []);
        $config = $builder->build($this->instance);

        self::assertFalse($config['discord']['enabled']);
        self::assertFalse($config['teamspeak']['enabled']);
    }

    public function testBuildIncludesStreamConfig(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $streamSettings = new MusicbotStreamSettings($customer, $this->instance, 'mb-1-slug123');
        $streamSettings->setEnabled(true);
        $streamSettings->setAccessMode(MusicbotStreamAccessMode::Public);
        $streamSettings->setStreamTitle('My Stream');
        $streamSettings->setBitrate(128);
        $streamSettings->setFormat('mp3');

        $builder = $this->buildService([], $streamSettings, []);
        $config = $builder->build($this->instance);

        self::assertTrue($config['stream']['enabled']);
        self::assertSame('mb-1-slug123', $config['stream']['public_slug']);
        self::assertSame('public', $config['stream']['access_mode']);
        self::assertSame('My Stream', $config['stream']['stream_title']);
        self::assertSame(128, $config['stream']['bitrate']);
        self::assertSame('mp3', $config['stream']['format']);
        self::assertFalse($config['stream']['has_token'], 'Stream token hash not set → has_token must be false.');
    }

    public function testBuildIncludesEnabledPluginConfigs(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $plugin = new MusicbotPlugin('nowplaying', 'Now Playing', '1.0.0', $customer, $this->instance);
        $plugin->setEnabled(true);
        $plugin->setConfig(['format' => '{title} by {artist}']);

        $disabledPlugin = new MusicbotPlugin('disabled-plugin', 'Disabled', '1.0.0', $customer, $this->instance);
        $disabledPlugin->setEnabled(false);

        $builder = $this->buildService([], null, [$plugin, $disabledPlugin]);
        $config = $builder->build($this->instance);

        self::assertCount(1, $config['plugins'], 'Disabled plugins must not appear in runtime config.');
        self::assertSame('nowplaying', $config['plugins'][0]['identifier']);
        self::assertSame(['format' => '{title} by {artist}'], $config['plugins'][0]['config']);
    }

    public function testBuildSanitizedStripsAllSecretFields(): void
    {
        $conn = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, [
            'guild_id' => 'gid-1',
            'voice_channel_id' => 'vc-1',
            'text_channel_id' => 'tc-1',
            'application_id' => 'app-1',
            'command_mode' => 'placeholder',
            'slash_commands_enabled' => false,
            'reconnect_policy' => 'manual',
        ], $this->secretService->encrypt(['bot_token' => 'tok-abc']));
        $conn->setEnabled(true);

        $builder = $this->buildService([$conn], null, []);
        $sanitized = $builder->buildSanitized($this->instance);

        self::assertArrayNotHasKey('bot_token', $sanitized['discord'], 'Sanitized config must not expose bot_token.');

        $json = json_encode($sanitized);
        self::assertIsString($json);
        self::assertStringNotContainsString('tok-abc', $json);
    }

    public function testBuildConfigHasServiceNameAndInstallPath(): void
    {
        $builder = $this->buildService([], null, []);
        $config = $builder->build($this->instance);

        self::assertSame('musicbot-test-abc123', $config['service_name']);
        self::assertSame('/var/lib/easywi/musicbot/musicbot-test-abc123', $config['install_path']);
        self::assertSame('0600', $config['config_file_permissions']);
    }

    /** @param MusicbotConnection[] $connections @param MusicbotPlugin[] $plugins */
    private function buildService(array $connections, ?MusicbotStreamSettings $streamSettings, array $plugins, ?MusicbotTeamspeakBackendConfig $teamspeakBackendConfig = null): MusicbotRuntimeConfigBuilder
    {
        $connectionRepo = $this->createStub(MusicbotConnectionRepository::class);
        $connectionRepo->method('findBy')->willReturn($connections);

        $streamRepo = $this->createStub(MusicbotStreamSettingsRepository::class);
        $streamRepo->method('findByInstance')->willReturn($streamSettings);

        $pluginRepo = $this->createStub(MusicbotPluginRepository::class);
        $pluginRepo->method('findBy')->willReturn($plugins);

        $teamspeakBackendRepo = $this->createStub(MusicbotTeamspeakBackendConfigRepository::class);
        $teamspeakBackendRepo->method('findOneByNode')->willReturn($teamspeakBackendConfig);

        return new MusicbotRuntimeConfigBuilder($connectionRepo, $streamRepo, $pluginRepo, $this->secretService, $teamspeakBackendRepo);
    }
}
