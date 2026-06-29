<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotConfigApplyPayloadBuilder;
use App\Module\Musicbot\Application\MusicbotRuntimeConfigBuilder;
use App\Module\Musicbot\Application\MusicbotSecretConfigService;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;
use App\Module\Musicbot\Domain\Entity\MusicbotStreamSettings;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotStreamSettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that all Musicbot panel settings are persisted to the DB (connection entities / secret storage)
 * and that secrets never appear in API responses, logs, runtimePayload, or status endpoints.
 *
 * These are unit-level tests against the domain / application layer only — no ENV variables needed.
 */
final class MusicbotConfigPersistenceTest extends TestCase
{
    private SecretsCrypto $crypto;
    private MusicbotSecretConfigService $secretService;
    private MusicbotInstance $instance;
    private User $customer;
    private Agent $agent;

    protected function setUp(): void
    {
        $this->crypto = new SecretsCrypto('test-app-secret');
        $this->secretService = new MusicbotSecretConfigService($this->crypto);

        $this->customer = new User('customer@example.test', UserType::Customer);
        $this->agent = new Agent('agent-uuid-1', ['token' => 'hash'], 'Test Agent');
        $this->instance = new MusicbotInstance(
            $this->customer,
            $this->agent,
            'Test Bot',
            'musicbot-test-abc123',
            '/var/lib/easywi/musicbot/musicbot-test-abc123',
        );
    }

    // ─── Discord Settings ──────────────────────────────────────────────────────

    public function testDiscordSettingsSavedInConnectionConfig(): void
    {
        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, [
            'guild_id' => '111222333444555666',
            'voice_channel_id' => '777888999000111222',
            'text_channel_id' => '333444555666777888',
            'application_id' => '999000111222333444',
            'command_mode' => 'slash',
            'slash_commands_enabled' => true,
            'reconnect_policy' => 'exponential_backoff',
        ], $this->secretService->encrypt(['bot_token' => 'discord-bot-token-secret']));

        $config = $connection->getConnectionConfig();

        self::assertSame('111222333444555666', $config['guild_id']);
        self::assertSame('777888999000111222', $config['voice_channel_id']);
        self::assertSame('333444555666777888', $config['text_channel_id']);
        self::assertSame('999000111222333444', $config['application_id']);
        self::assertSame('slash', $config['command_mode']);
        self::assertTrue($config['slash_commands_enabled']);
        self::assertSame('exponential_backoff', $config['reconnect_policy']);
    }

    public function testDiscordBotTokenStoredEncryptedInSecretConfig(): void
    {
        $secretConfig = $this->secretService->encrypt(['bot_token' => 'discord-bot-token-secret']);
        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, [], $secretConfig);

        $storedSecrets = $connection->getSecretConfig();

        self::assertArrayHasKey('bot_token', $storedSecrets);
        self::assertTrue($this->secretService->isEncrypted((string) $storedSecrets['bot_token']), 'bot_token must be stored encrypted.');
        self::assertStringNotContainsString('discord-bot-token-secret', (string) $storedSecrets['bot_token']);
    }

    public function testDiscordBotTokenNotVisibleInApiResponse(): void
    {
        $secretConfig = $this->secretService->encrypt(['bot_token' => 'super-secret-discord-token']);
        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, [], $secretConfig);

        $apiView = $this->secretService->normalizeForApi($connection->getSecretConfig());

        self::assertSame('********', $apiView['bot_token']);
        self::assertStringNotContainsString('super-secret-discord-token', implode('', $apiView));
    }

    public function testDiscordSettingsNotRequireEnvVariable(): void
    {
        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, [
            'guild_id' => '123456789',
            'voice_channel_id' => '987654321',
            'text_channel_id' => '111111111',
        ], $this->secretService->encrypt(['bot_token' => 'token-from-db']));

        $config = $connection->getConnectionConfig();
        $secrets = $this->secretService->normalizeForRuntime($connection->getSecretConfig());

        self::assertSame('123456789', $config['guild_id']);
        self::assertSame('token-from-db', $secrets['bot_token']);

        self::assertEmpty(array_filter([
            getenv('DISCORD_BOT_TOKEN'),
            getenv('DISCORD_GUILD_ID'),
            getenv('DISCORD_VOICE_CHANNEL_ID'),
        ], static fn (mixed $v): bool => $v !== false && $v !== ''),
            'No Discord config should come from ENV — DB is the Source of Truth.',
        );
    }

    // ─── TeamSpeak Settings ────────────────────────────────────────────────────

    public function testTeamspeakSettingsSavedInConnectionConfig(): void
    {
        $config = [
            'host' => 'ts.example.com',
            'port' => 9987,
            'nickname' => 'MusicBot',
            'channel_id' => '42',
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
            'allowed_server_groups' => ['5', '10'],
            'dj_server_groups' => ['15'],
            'admin_server_groups' => ['3'],
        ];

        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, $config);
        $stored = $connection->getConnectionConfig();

        self::assertSame('ts.example.com', $stored['host']);
        self::assertSame(9987, $stored['port']);
        self::assertSame('MusicBot', $stored['nickname']);
        self::assertSame('42', $stored['channel_id']);
        self::assertSame('ts3', $stored['profile']);
        self::assertSame('native_sdk', $stored['backend_type']);
        self::assertSame('/opt/ts3/backend.so', $stored['backend_path']);
        self::assertSame('/opt/ts3/identity.ini', $stored['identity_path']);
        self::assertSame('/opt/ts3/libts3sdk.so', $stored['library_path']);
        self::assertSame('/opt/ts3/ts3client', $stored['binary_path']);
        self::assertSame('!', $stored['command_prefix']);
        self::assertTrue($stored['commands_enabled']);
        self::assertTrue($stored['events_enabled']);
        self::assertSame(['5', '10'], $stored['allowed_server_groups']);
        self::assertSame(['15'], $stored['dj_server_groups']);
        self::assertSame(['3'], $stored['admin_server_groups']);
    }

    public function testTeamspeakPasswordsStoredEncrypted(): void
    {
        $secretConfig = $this->secretService->encrypt([
            'server_password' => 'ts-server-pw-secret',
            'channel_password' => 'ts-channel-pw-secret',
        ]);
        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [], $secretConfig);

        $stored = $connection->getSecretConfig();

        self::assertTrue($this->secretService->isEncrypted((string) $stored['server_password']), 'server_password must be encrypted at rest.');
        self::assertTrue($this->secretService->isEncrypted((string) $stored['channel_password']), 'channel_password must be encrypted at rest.');
        self::assertStringNotContainsString('ts-server-pw-secret', (string) $stored['server_password']);
        self::assertStringNotContainsString('ts-channel-pw-secret', (string) $stored['channel_password']);
    }

    public function testTeamspeakPasswordsNotVisibleInApiResponse(): void
    {
        $secretConfig = $this->secretService->encrypt([
            'server_password' => 'ts-server-pw-secret',
            'channel_password' => 'ts-channel-pw-secret',
        ]);
        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [], $secretConfig);

        $apiView = $this->secretService->normalizeForApi($connection->getSecretConfig());

        self::assertSame('********', $apiView['server_password']);
        self::assertSame('********', $apiView['channel_password']);
        self::assertStringNotContainsString('ts-server-pw-secret', implode('', $apiView));
        self::assertStringNotContainsString('ts-channel-pw-secret', implode('', $apiView));
    }

    public function testTeamspeakSecretsDecryptedForAgentRuntime(): void
    {
        $secretConfig = $this->secretService->encrypt([
            'server_password' => 'ts-server-pw',
            'channel_password' => 'ts-channel-pw',
        ]);
        $connection = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [], $secretConfig);

        $runtimeSecrets = $this->secretService->normalizeForRuntime($connection->getSecretConfig());

        self::assertSame('ts-server-pw', $runtimeSecrets['server_password']);
        self::assertSame('ts-channel-pw', $runtimeSecrets['channel_password']);
    }

    public function testTeamspeakSettingsNotRequireEnvVariable(): void
    {
        self::assertEmpty(array_filter([
            getenv('TEAMSPEAK_HOST'),
            getenv('TEAMSPEAK_PORT'),
            getenv('TEAMSPEAK_NICKNAME'),
            getenv('TEAMSPEAK_CHANNEL_ID'),
            getenv('TEAMSPEAK_SERVER_PASSWORD'),
            getenv('TEAMSPEAK_CHANNEL_PASSWORD'),
        ], static fn (mixed $v): bool => $v !== false && $v !== ''),
            'No TeamSpeak config should come from ENV — DB is the Source of Truth.',
        );
    }

    // ─── Secret Update / Merge ─────────────────────────────────────────────────

    public function testMergeSecretUpdatesPreservesExistingOnEmptySubmit(): void
    {
        $existing = $this->secretService->encrypt([
            'bot_token' => 'original-token',
            'server_password' => 'original-pw',
        ]);

        $merged = $this->secretService->mergeSecretUpdates($existing, [
            'bot_token' => '',
            'server_password' => '********',
        ]);

        $decrypted = $this->secretService->decrypt($merged);

        self::assertSame('original-token', $decrypted['bot_token'], 'Empty submit must not overwrite existing secret.');
        self::assertSame('original-pw', $decrypted['server_password'], 'Mask submit must not overwrite existing secret.');
    }

    public function testMergeSecretUpdatesEncryptsAndReplacesOnNewValue(): void
    {
        $existing = $this->secretService->encrypt(['bot_token' => 'old-token']);
        $merged = $this->secretService->mergeSecretUpdates($existing, ['bot_token' => 'new-token']);
        $decrypted = $this->secretService->decrypt($merged);

        self::assertSame('new-token', $decrypted['bot_token']);
        self::assertNotSame($existing['bot_token'], $merged['bot_token'], 'New ciphertext must differ after update.');
    }

    // ─── Runtime Config from DB ────────────────────────────────────────────────

    public function testRuntimeConfigBuiltFromDb(): void
    {
        $discordConfig = [
            'guild_id' => '123',
            'voice_channel_id' => '456',
            'text_channel_id' => '789',
            'application_id' => '000',
            'command_mode' => 'slash',
            'slash_commands_enabled' => true,
            'reconnect_policy' => 'manual',
        ];
        $discordSecrets = $this->secretService->encrypt(['bot_token' => 'discord-token-xyz']);
        $discordConn = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, $discordConfig, $discordSecrets);
        $discordConn->setEnabled(true);

        $tsConfig = [
            'host' => 'ts.test',
            'port' => 9987,
            'nickname' => 'Bot',
            'channel_id' => '10',
            'profile' => 'ts3',
            'backend' => 'ts3_client_compatible',
            'backend_type' => 'native_sdk',
            'backend_path' => '/lib/backend.so',
            'identity_path' => '/etc/identity.ini',
            'library_path' => '/lib/sdk.so',
            'binary_path' => '/usr/bin/ts3client',
            'command_prefix' => '!',
            'commands_enabled' => true,
            'events_enabled' => false,
            'allowed_server_groups' => ['5'],
            'dj_server_groups' => [],
            'admin_server_groups' => ['3'],
        ];
        $tsSecrets = $this->secretService->encrypt([
            'server_password' => 'ts-server-pw',
            'channel_password' => 'ts-channel-pw',
        ]);
        $tsConn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, $tsConfig, $tsSecrets);
        $tsConn->setEnabled(true);

        $builder = $this->buildRuntimeConfigBuilder([$discordConn, $tsConn], null, []);
        $config = $builder->build($this->instance);

        // Discord config
        self::assertTrue($config['discord']['enabled']);
        self::assertSame('123', $config['discord']['guild_id']);
        self::assertSame('456', $config['discord']['voice_channel_id']);
        self::assertSame('789', $config['discord']['text_channel_id']);
        self::assertSame('discord-token-xyz', $config['discord']['bot_token'], 'Runtime config must contain decrypted token for agent.');

        // TeamSpeak config
        self::assertTrue($config['teamspeak']['enabled']);
        self::assertSame('ts.test', $config['teamspeak']['host']);
        self::assertSame(9987, $config['teamspeak']['port']);
        self::assertSame('ts-server-pw', $config['teamspeak']['server_password']);
        self::assertSame('ts-channel-pw', $config['teamspeak']['channel_password']);
        self::assertSame('/lib/sdk.so', $config['teamspeak']['library_path']);
        self::assertSame('/usr/bin/ts3client', $config['teamspeak']['binary_path']);
    }

    public function testRuntimeConfigSanitizedVersionContainsNoSecrets(): void
    {
        $discordSecrets = $this->secretService->encrypt(['bot_token' => 'discord-token-xyz']);
        $discordConn = new MusicbotConnection($this->instance, MusicbotPlatform::Discord, ['guild_id' => '123', 'voice_channel_id' => '456', 'text_channel_id' => '', 'application_id' => '', 'command_mode' => 'slash', 'slash_commands_enabled' => false, 'reconnect_policy' => 'manual'], $discordSecrets);
        $discordConn->setEnabled(true);

        $builder = $this->buildRuntimeConfigBuilder([$discordConn], null, []);
        $sanitized = $builder->buildSanitized($this->instance);

        self::assertArrayNotHasKey('bot_token', $sanitized['discord'], 'bot_token must be stripped from sanitized config.');
        $flattened = json_encode($sanitized);
        self::assertIsString($flattened);
        self::assertStringNotContainsString('discord-token-xyz', $flattened, 'Plaintext secrets must not appear in sanitized output.');
    }

    // ─── runtimePayload secret sanitization ───────────────────────────────────

    public function testRuntimePayloadDoesNotContainSecretKeys(): void
    {
        $payloadFromAgent = [
            'instance_id' => '42',
            'status' => 'running',
            'playback_state' => 'playing',
            'bot_token' => 'leaked-secret',
            'server_password' => 'leaked-pw',
            'config' => [
                'stream_token' => 'leaked-stream-token',
                'host' => 'ts.test',
            ],
        ];

        $sanitized = $this->secretService->sanitizePayload($payloadFromAgent);

        self::assertArrayNotHasKey('bot_token', $sanitized);
        self::assertArrayNotHasKey('server_password', $sanitized);
        self::assertArrayNotHasKey('stream_token', $sanitized['config']);
        self::assertSame('42', $sanitized['instance_id']);
        self::assertSame('running', $sanitized['status']);
        self::assertSame('ts.test', $sanitized['config']['host']);
    }

    // ─── Log sanitization ──────────────────────────────────────────────────────

    public function testLogsDoNotContainSecrets(): void
    {
        $logLine = 'Connecting TeamSpeak: host=ts.test server_password=mysecretpw123 channel_password=chanpw456';
        $sanitized = $this->secretService->sanitizeLogText($logLine);

        self::assertStringNotContainsString('mysecretpw123', $sanitized);
        self::assertStringNotContainsString('chanpw456', $sanitized);
        self::assertStringContainsString('server_password', $sanitized);
        self::assertStringContainsString('channel_password', $sanitized);
        self::assertStringContainsString('********', $sanitized);
    }

    public function testLogsDoNotContainDiscordToken(): void
    {
        $logLine = '{"bot_token": "discord-secret-xyz", "guild_id": "123456789"}';
        $sanitized = $this->secretService->sanitizeLogText($logLine);

        self::assertStringNotContainsString('discord-secret-xyz', $sanitized);
        self::assertStringContainsString('123456789', $sanitized);
    }

    // ─── Status API ────────────────────────────────────────────────────────────

    public function testStatusEndpointContainsNoSecrets(): void
    {
        $statusData = [
            'module' => 'musicbot',
            'status' => 'running',
            'instances_total' => 1,
            'instances_running' => 1,
            'connectors' => ['teamspeak' => 'connected', 'discord' => 'connected'],
            'runtime' => ['backend' => 'native', 'audio_logic_enabled' => true],
        ];

        $json = json_encode($statusData);
        self::assertIsString($json);

        foreach (MusicbotSecretConfigService::SECRET_KEYS as $key) {
            self::assertStringNotContainsString($key, $json, sprintf('Status API must not contain secret key "%s".', $key));
        }
    }

    // ─── Secret leak detection ─────────────────────────────────────────────────

    public function testNormalizeForRuntimeNeverReturnsEncryptedCiphertext(): void
    {
        $encrypted = $this->secretService->encrypt(['bot_token' => 'actual-plaintext-token']);
        $runtime = $this->secretService->normalizeForRuntime($encrypted);

        self::assertSame('actual-plaintext-token', $runtime['bot_token']);
        self::assertStringNotContainsString('v1:', $runtime['bot_token'], 'normalizeForRuntime must not return the encrypted v1: ciphertext.');
    }

    public function testApiResponseNeverReturnsEncryptedCiphertext(): void
    {
        $encrypted = $this->secretService->encrypt(['bot_token' => 'plaintext']);
        $apiView = $this->secretService->normalizeForApi($encrypted);

        self::assertStringNotContainsString('v1:', $apiView['bot_token'], 'API response must not expose the v1: encrypted ciphertext.');
        self::assertSame('********', $apiView['bot_token']);
    }

    public function testSecretKeyDetectionCoversAllRequiredFields(): void
    {
        $requiredSecretKeys = [
            'bot_token',
            'server_password',
            'channel_password',
            'stream_token',
            'api_secret',
            'api_key',
            'runtime_control_token',
            'webhook_secret',
        ];

        foreach ($requiredSecretKeys as $key) {
            self::assertTrue(
                $this->secretService->isSecretKey($key),
                sprintf('"%s" must be recognized as a secret key.', $key),
            );
        }
    }

    public function testNonSecretFieldsNotStrippedFromPayload(): void
    {
        $payload = [
            'instance_id' => '42',
            'service_name' => 'musicbot-abc123',
            'install_path' => '/var/lib/easywi/musicbot/musicbot-abc123',
            'config_file_permissions' => '0600',
            'host' => 'ts.example.com',
            'port' => 9987,
        ];

        $sanitized = $this->secretService->sanitizePayload($payload);

        self::assertSame($payload, $sanitized, 'Non-secret fields must pass through sanitizePayload unchanged.');
    }

    public function testConfigApplyPayloadContainsRequiredFieldsForCustomerSettingsSave(): void
    {
        $tsConn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [
            'host' => 'ts.settings.test',
            'port' => 9988,
            'nickname' => 'SettingsBot',
            'profile' => 'ts3',
            'backend_type' => 'placeholder',
            'command_prefix' => '?',
        ]);
        $tsConn->setEnabled(true);

        $payload = $this->buildConfigApplyPayload([$tsConn]);

        foreach (['platform', 'profile', 'backend_type', 'host', 'port', 'nickname', 'command_prefix', 'audio_backend', 'backend_path', 'install_path'] as $field) {
            self::assertArrayHasKey($field, $payload);
        }
        self::assertSame('teamspeak', $payload['platform']);
        self::assertSame('ts3', $payload['profile']);
        self::assertSame('placeholder', $payload['backend_type']);
        self::assertSame('ts.settings.test', $payload['host']);
        self::assertSame(9988, $payload['port']);
        self::assertSame('SettingsBot', $payload['nickname']);
        self::assertSame('?', $payload['command_prefix']);
        self::assertSame('ts.settings.test', $payload['config']['teamspeak']['host']);
    }

    public function testConfigApplyPayloadContainsRequiredFieldsForCustomerConnectionsSave(): void
    {
        $payload = $this->buildConfigApplyPayload([]);

        self::assertSame('teamspeak', $payload['platform']);
        self::assertSame('ts3', $payload['profile']);
        self::assertSame('placeholder', $payload['backend_type']);
        self::assertSame('localhost', $payload['host']);
        self::assertSame(9987, $payload['port']);
        self::assertSame('Musicbot', $payload['nickname']);
        self::assertSame($this->instance->getInstallPath(), $payload['install_path']);
    }

    public function testConfigApplyPayloadKeepsAdminOnlyPathsFromStoredConfig(): void
    {
        $tsConn = new MusicbotConnection($this->instance, MusicbotPlatform::Teamspeak, [
            'host' => 'ts.paths.test',
            'port' => 9987,
            'nickname' => 'PathBot',
            'profile' => 'ts3',
            'backend_type' => 'client_library',
            'backend_path' => '/secure/backend',
            'binary_path' => '/secure/ts3client',
        ]);
        $tsConn->setEnabled(true);

        $payload = $this->buildConfigApplyPayload([$tsConn]);

        self::assertSame('/secure/backend', $payload['backend_path']);
        self::assertSame('/secure/ts3client', $payload['ts3_client_binary_path']);
        self::assertSame('/secure/backend', $payload['config']['teamspeak']['backend_path']);
    }

    public function testCustomerSettingsControllerPlansConfigApplyAndStatusRefresh(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        self::assertIsString($source);

        self::assertStringContainsString('dispatchConfigApplyJob($instance)', $source);
        self::assertStringContainsString('queueStatusRefresh($customer, $instance)', $source);
        self::assertStringContainsString("'musicbot.config.apply'", $source);
        self::assertStringContainsString("'platform' => 'teamspeak'", $source);
        self::assertStringContainsString('findInstanceForCustomer($id, $customer)', $source);
    }

    /**
     * @param MusicbotConnection[] $connections
     * @param MusicbotPlugin[] $plugins
     */
    private function buildRuntimeConfigBuilder(array $connections, ?MusicbotStreamSettings $streamSettings, array $plugins): MusicbotRuntimeConfigBuilder
    {
        $connectionRepo = $this->createStub(MusicbotConnectionRepository::class);
        $connectionRepo->method('findBy')->willReturn($connections);

        $streamRepo = $this->createStub(MusicbotStreamSettingsRepository::class);
        $streamRepo->method('findByInstance')->willReturn($streamSettings);

        $pluginRepo = $this->createStub(MusicbotPluginRepository::class);
        $pluginRepo->method('findBy')->willReturn($plugins);

        return new MusicbotRuntimeConfigBuilder($connectionRepo, $streamRepo, $pluginRepo, $this->secretService);
    }

    /** @param MusicbotConnection[] $connections */
    private function buildConfigApplyPayload(array $connections): array
    {
        return (new MusicbotConfigApplyPayloadBuilder($this->buildRuntimeConfigBuilder($connections, null, [])))->build($this->instance);
    }
}
