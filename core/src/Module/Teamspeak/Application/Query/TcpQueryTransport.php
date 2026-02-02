<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class TcpQueryTransport implements QueryTransportInterface
{
    /**
     * @param QueryCommand[] $commands
     */
    public function execute(array $commands, QueryContext $context): QueryResponse
    {
        if ($commands === []) {
            throw new QueryTransportException('No query commands provided.');
        }

        if (strtolower($context->tsVersion()) === 'ts6') {
            throw new QueryTransportException('TS6 requires SSH transport.');
        }

        throw new QueryTransportException('TCP transport is not enabled in this deployment.');
    }
}
