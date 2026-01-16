<?php

declare(strict_types=1);

namespace App\Service\Ts3;

use App\Dto\Ts3\CreateVirtualServerDto;
use App\Entity\Ts3Node;
use App\Entity\Ts3Token;
use App\Entity\Ts3VirtualServer;
use App\Service\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;

final class Ts3VirtualServerService
{
    public function __construct(
        private readonly AgentClient $agentClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
    ) {
    }

    public function createForCustomer(int $customerId, Ts3Node $node, CreateVirtualServerDto $dto): Ts3VirtualServer
    {
        $params = [];
        if ($dto->voicePort !== null) {
            $params['voice_port'] = $dto->voicePort;
        }
        if ($dto->filetransferPort !== null) {
            $params['filetransfer_port'] = $dto->filetransferPort;
        }

        $payload = [
            'name' => $dto->name,
            'params' => $params,
        ];

        $response = $this->agentClient->request($node, 'POST', '/v1/ts3/virtual-servers', $payload);
        $sid = (int) ($response['sid'] ?? 0);

        if ($sid <= 0) {
            throw new AgentBadResponseException('Agent did not return a virtual server id.');
        }

        $virtualServer = new Ts3VirtualServer($node, $customerId, $sid, $dto->name);
        $virtualServer->setVoicePort(isset($response['voice_port']) ? (int) $response['voice_port'] : $dto->voicePort);
        $virtualServer->setFiletransferPort(isset($response['filetransfer_port']) ? (int) $response['filetransfer_port'] : $dto->filetransferPort);
        $virtualServer->setStatus('running');
        $this->entityManager->persist($virtualServer);

        $token = $this->createOwnerToken($virtualServer);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $virtualServer;
    }

    public function start(Ts3VirtualServer $server): void
    {
        $this->applyServerAction($server, sprintf('/v1/ts3/virtual-servers/%d/start', $server->getSid()), 'running');
    }

    public function stop(Ts3VirtualServer $server): void
    {
        $this->applyServerAction($server, sprintf('/v1/ts3/virtual-servers/%d/stop', $server->getSid()), 'stopped');
    }

    public function recreate(Ts3VirtualServer $server): Ts3VirtualServer
    {
        $this->stop($server);
        $this->agentClient->request($server->getNode(), 'DELETE', sprintf('/v1/ts3/virtual-servers/%d', $server->getSid()));
        $server->archive();

        $dto = new CreateVirtualServerDto($server->getName(), $server->getVoicePort(), $server->getFiletransferPort());
        $replacement = $this->createForCustomer($server->getCustomerId(), $server->getNode(), $dto);

        $this->entityManager->flush();

        return $replacement;
    }

    public function rotateToken(Ts3VirtualServer $server): Ts3Token
    {
        $response = $this->agentClient->request(
            $server->getNode(),
            'POST',
            sprintf('/v1/ts3/virtual-servers/%d/tokens/rotate', $server->getSid()),
        );

        $tokenValue = (string) ($response['token'] ?? '');
        if ($tokenValue === '') {
            throw new AgentBadResponseException('Agent did not return a token.');
        }

        $existing = $this->findActiveToken($server);
        if ($existing !== null) {
            $existing->deactivate();
        }

        $token = new Ts3Token($server, $this->crypto->encrypt($tokenValue), 'owner');
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    private function applyServerAction(Ts3VirtualServer $server, string $endpoint, string $status): void
    {
        try {
            $this->agentClient->request($server->getNode(), 'POST', $endpoint);
            $server->setStatus($status);
        } catch (\Throwable $exception) {
            $server->setStatus('unknown');
        }

        $this->entityManager->flush();
    }

    private function createOwnerToken(Ts3VirtualServer $server): Ts3Token
    {
        $response = $this->agentClient->request(
            $server->getNode(),
            'POST',
            sprintf('/v1/ts3/virtual-servers/%d/tokens', $server->getSid()),
            ['type' => 'owner'],
        );

        $tokenValue = (string) ($response['token'] ?? '');
        if ($tokenValue === '') {
            throw new AgentBadResponseException('Agent did not return a token.');
        }

        return new Ts3Token($server, $this->crypto->encrypt($tokenValue), 'owner');
    }

    private function findActiveToken(Ts3VirtualServer $server): ?Ts3Token
    {
        $repository = $this->entityManager->getRepository(Ts3Token::class);

        return $repository->findOneBy(['virtualServer' => $server, 'active' => true]);
    }
}
