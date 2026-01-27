<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts3;

use App\Module\Core\Dto\Ts3\CreateVirtualServerDto;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts3Token;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;

final class Ts3VirtualServerService
{
    public function __construct(
        private readonly \App\Module\AgentOrchestrator\Application\AgentJobDispatcher $jobDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
    ) {
    }

    public function createForCustomer(int $customerId, Ts3Node $node, CreateVirtualServerDto $dto): Ts3VirtualServer
    {
        $filetransferPort = $dto->filetransferPort ?? $node->getFiletransferPort();
        $params = [];
        if ($dto->voicePort !== null) {
            $params['voice_port'] = $dto->voicePort;
        }
        if ($filetransferPort !== null) {
            $params['filetransfer_port'] = $filetransferPort;
        }

        $payload = [
            'name' => $dto->name,
            'params' => $params,
        ];

        $virtualServer = new Ts3VirtualServer($node, $customerId, 0, $dto->name);
        $virtualServer->setVoicePort($dto->voicePort);
        $virtualServer->setFiletransferPort($filetransferPort);
        $virtualServer->setStatus('provisioning');
        $this->entityManager->persist($virtualServer);

        $this->entityManager->flush();

        $jobPayload = [
            'virtual_server_id' => $virtualServer->getId(),
            'node_id' => $node->getId(),
            'name' => $dto->name,
            'params' => $params,
            'query_bind_ip' => $node->getQueryBindIp(),
            'query_port' => $node->getQueryPort(),
            'admin_username' => $node->getAdminUsername(),
            'admin_password' => $node->getAdminPassword($this->crypto),
        ];

        $this->jobDispatcher->dispatch($node->getAgent(), 'ts3.virtual.create', $jobPayload);
        $this->entityManager->flush();

        return $virtualServer;
    }

    public function start(Ts3VirtualServer $server): void
    {
        $this->applyServerAction($server, 'start');
    }

    public function stop(Ts3VirtualServer $server): void
    {
        $this->applyServerAction($server, 'stop');
    }

    public function recreate(Ts3VirtualServer $server): Ts3VirtualServer
    {
        $this->stop($server);
        $server->archive();

        $dto = new CreateVirtualServerDto($server->getName(), $server->getVoicePort(), $server->getFiletransferPort());
        $replacement = $this->createForCustomer($server->getCustomerId(), $server->getNode(), $dto);

        $this->entityManager->flush();

        return $replacement;
    }

    public function rotateToken(Ts3VirtualServer $server): Ts3Token
    {
        $jobPayload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'query_bind_ip' => $server->getNode()->getQueryBindIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];

        $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.token.rotate', $jobPayload);

        $token = new Ts3Token($server, $this->crypto->encrypt('pending'), 'owner');
        $token->deactivate();
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    public function delete(Ts3VirtualServer $server): void
    {
        $server->setStatus('deleting');
        $this->applyServerAction($server, 'delete');
    }

    private function applyServerAction(Ts3VirtualServer $server, string $action): void
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'action' => $action,
            'query_bind_ip' => $server->getNode()->getQueryBindIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];
        $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.action', $payload);
        $this->entityManager->flush();
    }

}
