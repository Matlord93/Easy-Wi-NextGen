<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\DunningReminder;

interface DunningNotificationSenderInterface
{
    public function send(DunningReminder $reminder): bool;
}
