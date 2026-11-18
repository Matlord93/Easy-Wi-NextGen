<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Infrastructure\Client;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface AgentGameServerClientInterface
{
    /**
     * @param array<int, array{proto: string, port: int}> $checks
     * @return array<int, array{proto: string, port: int, free: bool}>
     */
    public function checkFreePortsForNode(Agent $node, array $checks): array;
    /**
     * @param array<string, mixed> $payload
     * @return array{response: ResponseInterface, content_type: string|null}
     */
    public function openInstanceBackupDownload(Instance $instance, array $payload): array;
    /**
     * @return \Generator<int, string>
     */
    public function streamResponseContent(ResponseInterface $response): \Generator;
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createFromMasterTemplate(Instance $instance, array $payload): array;
    /**
     * @return array<string, mixed>
     */
    public function getInstanceStatus(Instance $instance): array;
    /**
     * @return array<string, mixed>
     */
    public function getInstanceAccessHealth(Instance $instance): array;
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function provisionInstanceAccess(Instance $instance, array $payload): array;
    /**
     * @return array<string, mixed>
     */
    public function getAccessCapabilities(Instance $instance): array;
    /**
     * @return array<string, mixed>
     */
    public function getConsoleLogs(Instance $instance, ?string $cursor = null): array;
}
