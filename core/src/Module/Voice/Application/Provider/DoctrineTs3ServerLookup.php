<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

use App\Repository\Ts3VirtualServerRepository;

final class DoctrineTs3ServerLookup implements Ts3ServerLookupInterface
{
    public function __construct(private readonly Ts3VirtualServerRepository $repository)
    {
    }

    public function find(string $externalId): ?array
    {
        $server = $this->repository->find((int) $externalId);
        if ($server === null) {
            return null;
        }

        return [
            'status' => strtolower($server->getStatus()),
            'public_host' => $server->getPublicHost(),
            'voice_port' => $server->getVoicePort(),
        ];
    }
}
