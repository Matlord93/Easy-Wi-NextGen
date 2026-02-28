<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class TeamSpeakQueryClient implements TeamSpeakQueryClientInterface
{
    public function __construct(
        private readonly QueryTransportInterface $transport,
    ) {
    }

    public function execute(QueryRequest $request, QueryContext $context): QueryResponse
    {
        return $this->transport->execute($request->commands(), $context);
    }
}
