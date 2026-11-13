<?php

declare(strict_types=1);

namespace App\Extension\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Public payload for extension.billing.before_automation listeners in extensions.
 *
 * @psalm-api
 */
final class BillingBeforeAutomationEvent extends Event
{
    public function __construct(public readonly \DateTimeImmutable $runAt)
    {
    }
}
