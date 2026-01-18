<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts6;

use App\Module\Core\Dto\Ts6\CreateVirtualServerDto;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Entity\Ts6Token;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;

final class Ts6VirtualServerService
{
    public function __construct(
        private readonly \App\Module\AgentOrchestrator\Application\AgentJobDispatcher $jobDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
    ) {
    }

    public function createForCustomer(int $customerId, Ts6Node $node, CreateVirtualServerDto $dto): Ts6VirtualServer
    {
        $params = ['slots' => $dto->slots];
        if ($dto->voicePort !== null) {
            $params['voice_port'] = $dto->voicePort;
        }

        $payload = [
            'name' => $dto->name,
            'params' => $params,
        ];

        $virtualServer = new Ts6VirtualServer($node, $customerId, 0, $dto->name, $dto->slots);
        $virtualServer->setVoicePort($dto->voicePort);
        $virtualServer->setFiletransferPort(null);
        $virtualServer->setStatus('provisioning');
        $this->entityManager->persist($virtualServer);
        $this->entityManager->flush();

        $jobPayload = [
            'virtual_server_id' => $virtualServer->getId(),
            'node_id' => $node->getId(),
            'name' => $dto->name,
            'params' => $params,
        ];
        $this->jobDispatcher->dispatch($node->getAgent(), 'ts6.virtual.create', $jobPayload);
        $this->entityManager->flush();

        return $virtualServer;
    }

    public function start(Ts6VirtualServer $server): void
    {
        $this->applyServerAction($server, 'start');
    }

    public function stop(Ts6VirtualServer $server): void
    {
        $this->applyServerAction($server, 'stop');
    }

    public function recreate(Ts6VirtualServer $server): Ts6VirtualServer
    {
        $this->stop($server);
        $server->archive();

        $dto = new CreateVirtualServerDto($server->getName(), $server->getSlots(), $server->getVoicePort());
        $replacement = $this->createForCustomer($server->getCustomerId(), $server->getNode(), $dto);

        $this->entityManager->flush();

        return $replacement;
    }

    public function rotateToken(Ts6VirtualServer $server): Ts6Token
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
        ];
        $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts6.virtual.token.rotate', $payload);

        $token = new Ts6Token($server, $this->crypto->encrypt('pending'), 'owner');
        $token->deactivate();
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    private function applyServerAction(Ts6VirtualServer $server, string $action): void
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'action' => $action,
        ];
        $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts6.virtual.action', $payload);
        $this->entityManager->flush();
    }

}
