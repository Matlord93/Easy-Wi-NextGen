<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;

interface QueryAdapterInterface
{
    public function supports(string $type): bool;

    public function query(Instance $instance, QueryContext $context): QueryResult;
}
