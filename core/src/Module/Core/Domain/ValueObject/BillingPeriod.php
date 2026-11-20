<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\ValueObject;

final class BillingPeriod
{
    /** Calendar months retain the billing day, clamped to the target month's last day. */
    public static function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        if ($months < 1 || $months > 36) {
            throw new \InvalidArgumentException('Billing period must be between 1 and 36 months.');
        }
        $target = $date->modify('first day of this month')->modify('+'.$months.' months');

        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'), min((int) $date->format('d'), (int) $target->format('t')));
    }
}
