<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Instance;

final class NoneQueryAdapter implements QueryAdapterInterface
{
    #[\Override]
    public function supports(string $type): bool
    {
        return $type === 'none' || $type === '';
    }

    #[\Override]
    public function query(Instance $instance, QueryContext $context): QueryResult
    {
        return QueryResult::unavailable('Query disabled.');
    }
}
