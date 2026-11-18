<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\SinusbotInstance;

/** @extends \App\Repository\EntityRepositoryQueryInterface<SinusbotInstance> */
interface SinusbotInstanceRepositoryInterface extends \App\Repository\EntityRepositoryQueryInterface
{
    public function countBotsUsedForInstance(SinusbotInstance $instance): int;
}
