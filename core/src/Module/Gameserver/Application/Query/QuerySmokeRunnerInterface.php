<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;

interface QuerySmokeRunnerInterface
{
    /** @return array<string,mixed> */
    public function run(Instance $instance, bool $retryOnTimeout = true): array;
}
