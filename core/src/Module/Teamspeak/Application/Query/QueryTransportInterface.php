<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

interface QueryTransportInterface
{
    /**
     * @param QueryCommand[] $commands
     */
    public function execute(array $commands, QueryContext $context): QueryResponse;
}
