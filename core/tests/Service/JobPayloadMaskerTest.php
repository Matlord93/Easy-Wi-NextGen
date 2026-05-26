<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\JobPayloadMasker;
use PHPUnit\Framework\TestCase;

final class JobPayloadMaskerTest extends TestCase
{
    public function testMasksNestedPayload(): void
    {
        $masker = new JobPayloadMasker();

        $payload = [
            'password' => 'super-secret',
            'authorized_keys' => 'ssh-ed25519 AAA',
            'nested' => [
                'token' => 'token-value',
                'items' => [
                    ['apiKey' => 'abc123'],
                    ['value' => 'ok'],
                ],
            ],
            'list' => [
                ['sshKey' => 'ssh-rsa AAA'],
                ['name' => 'demo'],
            ],
            'regular' => 'plain',
        ];

        $masked = $masker->maskPayload($payload);

        self::assertSame('[redacted]', $masked['password']);
        self::assertSame('[redacted]', $masked['authorized_keys']);
        self::assertSame('[redacted]', $masked['nested']['token']);
        self::assertSame('[redacted]', $masked['nested']['items'][0]['apiKey']);
        self::assertSame('ok', $masked['nested']['items'][1]['value']);
        self::assertSame('[redacted]', $masked['list'][0]['sshKey']);
        self::assertSame('demo', $masked['list'][1]['name']);
        self::assertSame('plain', $masked['regular']);
    }

    public function testMasksRenderedConfigContentsAndSensitiveVariables(): void
    {
        $masker = new JobPayloadMasker();

        $payload = [
            'type' => 'instance.config.apply',
            'files' => [
                [
                    'path' => 'server.cfg',
                    'content' => 'rcon_password super-secret',
                    'content_base64' => base64_encode('server_password secret'),
                ],
            ],
            'variables' => [
                'hostname' => 'Example',
                'steam_gslt' => 'gslt-token',
                'rcon_password' => 'rcon-secret',
            ],
        ];

        $masked = $masker->maskPayload($payload);

        self::assertSame('[redacted]', $masked['files'][0]['content']);
        self::assertSame('[redacted]', $masked['files'][0]['content_base64']);
        self::assertSame('[redacted]', $masked['variables']['steam_gslt']);
        self::assertSame('[redacted]', $masked['variables']['rcon_password']);
        self::assertSame('Example', $masked['variables']['hostname']);
    }

    public function testMasksJsonStrings(): void
    {
        $masker = new JobPayloadMasker();

        $input = '{"authorization":"Bearer abc","nested":{"private_key":"pem"}}';

        $masked = $masker->maskText($input);
        $decoded = json_decode($masked, true);

        self::assertIsArray($decoded);
        self::assertSame('[redacted]', $decoded['authorization']);
        self::assertSame('[redacted]', $decoded['nested']['private_key']);
    }

    public function testMasksAdditionalDatabaseSensitiveKeys(): void
    {
        $masker = new JobPayloadMasker();

        $payload = [
            'admin_secret' => 'root-secret',
            'admin_password' => 'root-pass',
            'database_password' => 'db-pass',
            'encryptedAdminSecret' => ['ciphertext' => 'abc'],
            'encryptedOneTimeCredential' => ['ciphertext' => 'def'],
            'private_key' => 'pem',
            'safe' => 'value',
        ];

        $masked = $masker->maskPayload($payload);

        self::assertSame('[redacted]', $masked['admin_secret']);
        self::assertSame('[redacted]', $masked['admin_password']);
        self::assertSame('[redacted]', $masked['database_password']);
        self::assertSame('[redacted]', $masked['encryptedAdminSecret']);
        self::assertSame('[redacted]', $masked['encryptedOneTimeCredential']);
        self::assertSame('[redacted]', $masked['private_key']);
        self::assertSame('value', $masked['safe']);
    }


    public function testMasksOneTimeDatabaseCredentialFields(): void
    {
        $masker = new JobPayloadMasker();

        $payload = [
            'one_time_credential' => 'temporary-secret',
            'new_password' => 'pw-123',
            'password' => 'pw-456',
        ];

        $masked = $masker->maskPayload($payload);

        self::assertSame('[redacted]', $masked['one_time_credential']);
        self::assertSame('[redacted]', $masked['new_password']);
        self::assertSame('[redacted]', $masked['password']);
    }

}
