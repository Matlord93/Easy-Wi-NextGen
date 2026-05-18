<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

use App\Repository\Ts6VirtualServerRepository;

final class DoctrineTs6ServerLookup implements Ts6ServerLookupInterface
{
    public function __construct(private readonly Ts6VirtualServerRepository $repository)
    {
    }

    public function find(string $externalId): ?array
    {
        $server = $this->repository->find((int) $externalId);
        if ($server === null) {
            return null;
        }

        $agentIp = $server->getNode()->getAgent()->getLastHeartbeatIp();
        $publicIp = ($agentIp !== null && !str_starts_with($agentIp, '127.') && $agentIp !== '::1' && $agentIp !== 'localhost') ? $agentIp : null;

        return [
            'status' => strtolower($server->getStatus()),
            'public_host' => $server->getPublicHost(),
            'node_public_ip' => $publicIp,
            'voice_port' => $server->getVoicePort(),
            'slots' => $server->getSlots(),
        ];
    }
}
