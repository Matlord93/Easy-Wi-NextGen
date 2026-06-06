<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Runtime;

use App\Infrastructure\Runtime\MemoryLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MemoryLimitTest extends TestCase
{
    #[DataProvider('validLimits')]
    public function testConvertsIniValuesToBytes(string $value, int $expected): void
    {
        self::assertSame($expected, MemoryLimit::toBytes($value));
    }

    /** @return iterable<string, array{string, int}> */
    public static function validLimits(): iterable
    {
        yield 'unlimited' => ['-1', -1];
        yield 'bytes' => ['1024', 1024];
        yield 'kilobytes' => ['128K', 128 * 1024];
        yield 'megabytes with whitespace' => [' 128m ', 128 * 1024 * 1024];
        yield 'gigabytes' => ['2G', 2 * 1024 * 1024 * 1024];
    }

    public function testRejectsUnknownIniValues(): void
    {
        self::assertNull(MemoryLimit::toBytes('unlimited'));
        self::assertNull(MemoryLimit::toBytes('128MB'));
        self::assertNull(MemoryLimit::toBytes(''));
    }
}
