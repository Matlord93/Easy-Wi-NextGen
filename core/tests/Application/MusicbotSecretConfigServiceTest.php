<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Musicbot\Application\MusicbotSecretConfigService;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use PHPUnit\Framework\TestCase;

final class MusicbotSecretConfigServiceTest extends TestCase
{
    private SecretsCrypto $crypto;
    private MusicbotSecretConfigService $service;

    protected function setUp(): void
    {
        $this->crypto = new SecretsCrypto('test-app-secret');
        $this->service = new MusicbotSecretConfigService($this->crypto);
    }

    public function testNormalizeForApiNeverReturnsPlaintextToken(): void
    {
        $encrypted = $this->service->encrypt(['bot_token' => 'super-secret-token', 'server_password' => 'pw123']);
        $masked = $this->service->normalizeForApi($encrypted);

        self::assertSame('********', $masked['bot_token']);
        self::assertSame('********', $masked['server_password']);
        self::assertStringNotContainsString('super-secret-token', implode('', $masked));
        self::assertStringNotContainsString('pw123', implode('', $masked));
    }

    public function testNormalizeForApiReturnsEmptyStringForEmptySecrets(): void
    {
        $masked = $this->service->normalizeForApi(['bot_token' => '', 'server_password' => '']);

        self::assertSame('', $masked['bot_token']);
        self::assertSame('', $masked['server_password']);
    }

    public function testSanitizeLogTextMasksTokenInLogs(): void
    {
        $log = 'Connecting with bot_token: abc123secret and stream_token=xyz789';
        $sanitized = $this->service->sanitizeLogText($log);

        self::assertStringNotContainsString('abc123secret', $sanitized);
        self::assertStringNotContainsString('xyz789', $sanitized);
        self::assertStringContainsString('bot_token', $sanitized);
        self::assertStringContainsString('stream_token', $sanitized);
        self::assertStringContainsString('********', $sanitized);
    }

    public function testSanitizeLogTextMasksJsonStyleSecrets(): void
    {
        $log = '{"bot_token": "discord-bot-secret", "channel_password": "channel-pw"}';
        $sanitized = $this->service->sanitizeLogText($log);

        self::assertStringNotContainsString('discord-bot-secret', $sanitized);
        self::assertStringNotContainsString('channel-pw', $sanitized);
    }

    public function testSanitizePayloadStripsSecretKeysForRuntimeStatus(): void
    {
        $payload = [
            'instance_id' => '42',
            'bot_token' => 'top-secret',
            'server_password' => 'pw',
            'config' => [
                'stream_token' => 'stream-secret',
                'port' => 9987,
            ],
        ];

        $sanitized = $this->service->sanitizePayload($payload);

        self::assertArrayNotHasKey('bot_token', $sanitized);
        self::assertArrayNotHasKey('server_password', $sanitized);
        self::assertArrayNotHasKey('stream_token', $sanitized['config']);
        self::assertSame('42', $sanitized['instance_id']);
        self::assertSame(9987, $sanitized['config']['port']);
    }

    public function testConfigFilePermissionsKeyNotConsideredSecret(): void
    {
        $payload = [
            'instance_id' => '42',
            'config_file_permissions' => '0600',
            'bot_token' => 'secret',
        ];

        $sanitized = $this->service->sanitizePayload($payload);

        self::assertSame('0600', $sanitized['config_file_permissions']);
        self::assertArrayNotHasKey('bot_token', $sanitized);
    }

    public function testSecretRotationProducesDifferentCiphertext(): void
    {
        $connection = new MusicbotConnection(
            $this->createMockInstance(),
            MusicbotPlatform::Discord,
        );
        $connection->setSecretConfig(['bot_token' => '']);

        $this->service->rotateSecret($connection, 'bot_token', 'my-bot-token');
        $first = $connection->getSecretConfig()['bot_token'];

        $this->service->rotateSecret($connection, 'bot_token', 'my-bot-token');
        $second = $connection->getSecretConfig()['bot_token'];

        self::assertTrue($this->service->isEncrypted($first));
        self::assertTrue($this->service->isEncrypted($second));
        self::assertNotSame($first, $second, 'Each rotation must produce a unique ciphertext due to random nonce.');
    }

    public function testDecryptRoundTrip(): void
    {
        $plaintext = ['bot_token' => 'discord-token', 'server_password' => 'ts-password'];
        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);

        self::assertSame($plaintext, $decrypted);
    }

    public function testEncryptSkipsAlreadyEncryptedValues(): void
    {
        $alreadyEncrypted = $this->crypto->encrypt('original-value');
        $result = $this->service->encrypt(['bot_token' => $alreadyEncrypted]);

        self::assertSame($alreadyEncrypted, $result['bot_token'], 'Already-encrypted v1: values must not be double-encrypted.');
    }

    public function testMergeSecretUpdatesKeepsExistingWhenEmptyOrMaskSubmitted(): void
    {
        $existing = $this->service->encrypt(['bot_token' => 'original-token', 'server_password' => 'original-pw']);

        $merged = $this->service->mergeSecretUpdates($existing, [
            'bot_token' => '',
            'server_password' => '********',
        ]);

        self::assertSame($existing['bot_token'], $merged['bot_token']);
        self::assertSame($existing['server_password'], $merged['server_password']);
    }

    public function testMergeSecretUpdatesEncryptsNewValue(): void
    {
        $existing = $this->service->encrypt(['bot_token' => 'old-token']);

        $merged = $this->service->mergeSecretUpdates($existing, ['bot_token' => 'new-token']);
        $decrypted = $this->service->decrypt($merged);

        self::assertSame('new-token', $decrypted['bot_token']);
    }

    public function testNormalizeForRuntimeDecryptsForAgent(): void
    {
        $encrypted = $this->service->encrypt(['bot_token' => 'runtime-token', 'stream_token' => 'stream-value']);
        $runtime = $this->service->normalizeForRuntime($encrypted);

        self::assertSame('runtime-token', $runtime['bot_token']);
        self::assertSame('stream-value', $runtime['stream_token']);
    }

    public function testLegacyPlaintextTreatedAsUnencrypted(): void
    {
        $legacy = ['bot_token' => 'plaintext-no-prefix', 'server_password' => ''];
        $decrypted = $this->service->decrypt($legacy);

        self::assertSame('plaintext-no-prefix', $decrypted['bot_token']);
        self::assertSame('', $decrypted['server_password']);
    }

    private function createMockInstance(): \App\Module\Musicbot\Domain\Entity\MusicbotInstance
    {
        $customer = new \App\Module\Core\Domain\Entity\User('customer@example.test', \App\Module\Core\Domain\Enum\UserType::Customer);
        $agent = new \App\Module\Core\Domain\Entity\Agent('agent-1', ['token' => 'hash'], 'Test Agent');

        return new \App\Module\Musicbot\Domain\Entity\MusicbotInstance($customer, $agent, 'Test Bot', 'test-bot', '/srv/musicbot/test');
    }
}
