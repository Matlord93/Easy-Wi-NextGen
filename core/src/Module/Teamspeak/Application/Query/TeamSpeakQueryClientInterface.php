<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

interface TeamSpeakQueryClientInterface
{
    public function execute(QueryRequest $request, QueryContext $context): QueryResponse;

    public function listClients(QueryContext $context): QueryResponse;

    public function listChannels(QueryContext $context): QueryResponse;

    /**
     * @param array<string, scalar|null> $properties
     */
    public function createChannel(QueryContext $context, array $properties): QueryResponse;

    /**
     * @param array<string, scalar|null> $properties
     */
    public function createToken(QueryContext $context, array $properties): QueryResponse;

    /**
     * @param array<string, scalar|null> $properties
     */
    public function banClient(QueryContext $context, array $properties): QueryResponse;
}
