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
}
