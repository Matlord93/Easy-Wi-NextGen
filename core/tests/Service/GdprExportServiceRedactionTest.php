<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\GdprExportService;
use PHPUnit\Framework\TestCase;

final class GdprExportServiceRedactionTest extends TestCase
{
    public function testRedactsSecretsTokensAndPrivateKeysRecursively(): void
    {
        $service = (new \ReflectionClass(GdprExportService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(GdprExportService::class, 'redactSensitiveData');
        $method->setAccessible(true);

        $input = [
            'api_token' => 'abc',
            'nested' => [
                'private_key' => '-----BEGIN PRIVATE KEY-----',
                'password' => 'topsecret',
                'client_secret' => 's3cr3t',
            ],
            'safe' => 'value',
        ];

        $output = $method->invoke($service, $input);

        self::assertSame('[REDACTED]', $output['api_token']);
        self::assertSame('[REDACTED]', $output['nested']['private_key']);
        self::assertSame('[REDACTED]', $output['nested']['password']);
        self::assertSame('[REDACTED]', $output['nested']['client_secret']);
        self::assertSame('value', $output['safe']);
    }
}
