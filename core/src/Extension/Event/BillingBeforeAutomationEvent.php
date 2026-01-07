<?php

declare(strict_types=1);

namespace App\Extension\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class BillingBeforeAutomationEvent extends Event
{
    public function __construct(public readonly \DateTimeImmutable $runAt)
    {
    }
}
