<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;

final class RconQueryAdapter implements QueryAdapterInterface
{
    public function supports(string $type): bool
    {
        return $type === 'rcon';
    }

    public function query(Instance $instance, QueryContext $context): QueryResult
    {
        return QueryResult::unavailable('RCON query delegated to agent.');
    }
}
