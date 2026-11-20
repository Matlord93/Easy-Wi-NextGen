<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\ValueObject;

/** One cent is stored as 43,200 units, so minute charging never uses floats. */
final class MinuteTariff
{
    public const int UNITS_PER_CENT = 43_200;

    public static function minutes(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        $seconds = $end->getTimestamp() - $start->getTimestamp();

        return $seconds <= 0 ? 0 : intdiv($seconds + 59, 60);
    }

    public static function centsToUnits(int $cents): int
    {
        if ($cents < 0 || $cents > 2_147_483_647) {
            throw new \InvalidArgumentException('Invalid money amount.');
        }

        return $cents * self::UNITS_PER_CENT;
    }
}
