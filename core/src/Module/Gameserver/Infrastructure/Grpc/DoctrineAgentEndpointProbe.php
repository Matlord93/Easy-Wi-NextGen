<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Grpc;

use App\Module\Gameserver\Application\Console\AgentEndpointProbeInterface;
use App\Repository\AgentRepository;

final class DoctrineAgentEndpointProbe implements AgentEndpointProbeInterface
{
    public function __construct(private readonly AgentRepository $agentRepository)
    {
    }

    public function hasAnyEndpoint(): bool
    {
        $agent = $this->agentRepository->findOneBy([], ['updatedAt' => 'DESC']);
        if ($agent === null) {
            return false;
        }

        $metadata = $agent->getMetadata() ?? [];
        $grpcEndpoint = trim((string) ($metadata['grpc_endpoint'] ?? ''));
        if ($grpcEndpoint !== '') {
            return true;
        }

        return trim($agent->getServiceBaseUrl()) !== '';
    }
}
