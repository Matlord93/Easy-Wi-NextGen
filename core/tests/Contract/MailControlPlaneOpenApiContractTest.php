<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class MailControlPlaneOpenApiContractTest extends TestCase
{
    public function testMailBackendEnumAndDisabledErrorAreDocumented(): void
    {
        $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/docs/api/openapi-mail-control-plane-v1.yaml');

        $backendEnum = $spec['components']['schemas']['MailBackend']['enum'] ?? [];
        self::assertSame(['none', 'local', 'panel', 'external'], $backendEnum);

        $capabilities = $spec['components']['schemas']['DomainCapabilities'] ?? null;
        self::assertIsArray($capabilities);
        self::assertSame(['webspace', 'mail'], $capabilities['required'] ?? []);

        $errorSchema = $spec['components']['schemas']['MailBackendDisabledError'] ?? null;
        self::assertIsArray($errorSchema);
        self::assertContains('error_code', $errorSchema['required'] ?? []);
        self::assertSame(['MAIL_BACKEND_DISABLED'], $errorSchema['properties']['error_code']['enum'] ?? []);
    }
}
