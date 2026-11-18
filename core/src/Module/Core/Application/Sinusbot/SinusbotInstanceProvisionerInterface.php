<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Sinusbot;

use App\Module\Core\Domain\Entity\SinusbotInstance;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\User;

interface SinusbotInstanceProvisionerInterface
{
    public function createInstanceForCustomer(User $customer, SinusbotNode $node, int $quota, ?string $username = null): SinusbotInstance;

    public function startInstance(SinusbotInstance $instance): void;

    public function stopInstance(SinusbotInstance $instance): void;

    public function restartInstance(SinusbotInstance $instance): void;

    public function deleteInstance(SinusbotInstance $instance): void;

    public function resetPassword(SinusbotInstance $instance): string;

    public function syncStatus(SinusbotInstance $instance): void;

    public function updateQuota(SinusbotInstance $instance, int $quota): void;
}
