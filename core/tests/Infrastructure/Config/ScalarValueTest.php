<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Config;

use App\Infrastructure\Config\ScalarValue;
use PHPUnit\Framework\TestCase;

final class ScalarValueTest extends TestCase
{
    public function testTextPreservesKnownZeroAndRejectsStructuredValues(): void
    {
        self::assertSame('0', ScalarValue::text(0, 'fallback'));
        self::assertSame('0', ScalarValue::text('0', 'fallback'));
        self::assertSame('fallback', ScalarValue::text(['invalid'], 'fallback'));
        self::assertSame('', ScalarValue::text(new \stdClass()));
    }

    public function testIntegerAcceptsNumericSettingsAndUsesDefaultForStructuredData(): void
    {
        self::assertSame(0, ScalarValue::integer('0', 7));
        self::assertSame(12, ScalarValue::integer('12', 7));
        self::assertSame(7, ScalarValue::integer(['invalid'], 7));
        self::assertSame(7, ScalarValue::integer(null, 7));
    }
}
