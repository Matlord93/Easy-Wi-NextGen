<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class TeamSpeakQueryClient implements TeamSpeakQueryClientInterface
{
    public function __construct(
        private readonly QueryTransportInterface $transport,
        private readonly QueryCommandBuilder $commandBuilder,
    ) {
    }

    public function execute(QueryRequest $request, QueryContext $context): QueryResponse
    {
        return $this->transport->execute($request->commands(), $context);
    }

    public function listClients(QueryContext $context): QueryResponse
    {
        return $this->transport->execute([
            $this->commandBuilder->build('clientlist'),
        ], $context);
    }

    public function listChannels(QueryContext $context): QueryResponse
    {
        return $this->transport->execute([
            $this->commandBuilder->build('channellist'),
        ], $context);
    }

    /**
     * @param array<string, scalar|null> $properties
     */
    public function createChannel(QueryContext $context, array $properties): QueryResponse
    {
        return $this->transport->execute([
            $this->commandBuilder->build('channelcreate', $properties),
        ], $context);
    }

    /**
     * @param array<string, scalar|null> $properties
     */
    public function createToken(QueryContext $context, array $properties): QueryResponse
    {
        return $this->transport->execute([
            $this->commandBuilder->build('tokenadd', $properties),
        ], $context);
    }

    /**
     * @param array<string, scalar|null> $properties
     */
    public function banClient(QueryContext $context, array $properties): QueryResponse
    {
        return $this->transport->execute([
            $this->commandBuilder->build('banclient', $properties),
        ], $context);
    }
}
