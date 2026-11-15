<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\MetricSampleRow;
use PHPUnit\Framework\TestCase;

final class MetricSampleRowTest extends TestCase
{
    public function testNumericDriverValuesAndNullsKeepMetricTypes(): void
    {
        self::assertSame(4294967296, MetricSampleRow::nullableInteger('4294967296'));
        self::assertSame(12.5, MetricSampleRow::number('12.5'));
        self::assertNull(MetricSampleRow::number(null));
        self::assertNull(MetricSampleRow::nullableInteger('not-a-number'));
        self::assertNull(MetricSampleRow::nullableText(['error']));
        $date = new \DateTimeImmutable('2026-01-01');
        self::assertSame($date, MetricSampleRow::date($date));
        self::assertSame(['load' => 2], MetricSampleRow::payload(['load' => 2]));
    }

    public function testMappingChecksRowsAndProducesAList(): void
    {
        self::assertSame([1, 2], MetricSampleRow::map(
            [10 => ['id' => '1'], 20 => ['id' => 2]],
            static fn (array $row): int => MetricSampleRow::integer($row['id'] ?? null)
        ));
        self::assertSame([], MetricSampleRow::map([], static fn (array $row): array => $row));
    }

    public function testInvalidRowIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        MetricSampleRow::map([false], static fn (array $row): array => $row);
    }

    public function testInvalidResultIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        MetricSampleRow::map(false, static fn (array $row): array => $row);
    }

    public function testMissingRequiredDateIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        MetricSampleRow::date(null);
    }
}
