<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;

final class A2sQueryAdapter implements QueryAdapterInterface
{
    public function supports(string $type): bool
    {
        return $type === 'a2s' || $type === 'steam_a2s';
    }

    public function query(Instance $instance, QueryContext $context): QueryResult
    {
        return QueryResult::unavailable('A2S query delegated to agent.');
    }
}
