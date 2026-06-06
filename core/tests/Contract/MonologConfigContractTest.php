<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class MonologConfigContractTest extends TestCase
{
    public function testMainLogSuppressesExpectedClientRoutingErrors(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/monolog.yaml');
        $main = $config['monolog']['handlers']['main'] ?? null;

        self::assertIsArray($main);
        self::assertSame('fingers_crossed', $main['type'] ?? null);
        self::assertSame('error', $main['action_level'] ?? null);
        self::assertSame('main_group', $main['handler'] ?? null);
        self::assertSame([404, 405], $main['excluded_http_codes'] ?? null);
    }
}
