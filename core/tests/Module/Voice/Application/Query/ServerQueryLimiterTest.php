<?php

declare(strict_types=1);

namespace App\Tests\Module\Voice\Application\Query;

use App\Module\Voice\Application\Query\ServerQueryLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ServerQueryLimiterTest extends TestCase
{
    public function testPreviouslyCachedDeadlineStillBlocksTheQuery(): void
    {
        $cache = new ArrayAdapter();
        $cache->get('ts_query_rate_limit_server', static fn (): array => ['next_allowed_at' => time() + 3600, 'delay' => 4]);
        $result = (new ServerQueryLimiter($cache))->allow('server', 2, 8);

        self::assertFalse($result->isAllowed());
        self::assertGreaterThan(0, $result->getRetryAfterSeconds());
    }

    public function testMalformedCachedValuesFallBackToANewRateLimitWindow(): void
    {
        $cache = new ArrayAdapter();
        $cache->get('ts_query_rate_limit_server', static fn (): array => ['next_allowed_at' => ['invalid'], 'delay' => ['invalid']]);
        $limiter = new ServerQueryLimiter($cache);
        $result = $limiter->allow('server', 2, 8);

        self::assertTrue($result->isAllowed());
        self::assertSame(4, $result->getRetryAfterSeconds());
        self::assertFalse($limiter->allow('server', 2, 8)->isAllowed());
    }
}
