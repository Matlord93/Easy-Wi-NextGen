<?php

declare(strict_types=1);

namespace App\Tests\Module\Teamspeak\Application\Query;

use App\Module\Teamspeak\Application\Query\QueryThrottle;
use PHPUnit\Framework\TestCase;

final class QueryThrottleTest extends TestCase
{
    public function testConsumesTokensPerScope(): void
    {
        $throttle = new QueryThrottle(2, 1.0);

        self::assertTrue($throttle->allow('server:1', 1, 100.0));
        self::assertTrue($throttle->allow('server:1', 1, 100.0));
        self::assertFalse($throttle->allow('server:1', 1, 100.0));

        self::assertTrue($throttle->allow('server:2', 1, 100.0));
    }

    public function testRefillsTokensOverTime(): void
    {
        $throttle = new QueryThrottle(2, 1.0);

        self::assertTrue($throttle->allow('server:1', 2, 10.0));
        self::assertFalse($throttle->allow('server:1', 1, 10.0));
        self::assertTrue($throttle->allow('server:1', 1, 11.0));
    }
}
