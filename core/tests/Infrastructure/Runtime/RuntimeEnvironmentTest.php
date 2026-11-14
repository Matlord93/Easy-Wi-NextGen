<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Runtime;

use App\Infrastructure\Runtime\RuntimeEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RuntimeEnvironmentTest extends TestCase
{
    public function testEnvironmentNameIsPreserved(): void
    {
        self::assertSame('test', RuntimeEnvironment::name('test'));
    }

    #[DataProvider('invalidNames')]
    public function testInvalidEnvironmentValuesAreRejected(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuntimeEnvironment::name($value);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'missing' => [null];
        yield 'boolean' => [true];
        yield 'array' => [['prod']];
    }
}
