<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

interface TeamSpeakQueryClientInterface
{
    public function execute(QueryRequest $request, QueryContext $context): QueryResponse;
}
