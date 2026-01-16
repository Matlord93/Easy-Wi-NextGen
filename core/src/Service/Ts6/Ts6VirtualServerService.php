<?php

declare(strict_types=1);

namespace App\Service\Ts6;

use App\Dto\Ts6\CreateVirtualServerDto;
use App\Entity\Ts6Node;
use App\Entity\Ts6Token;
use App\Entity\Ts6VirtualServer;
use App\Service\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;

final class Ts6VirtualServerService
{
    public function __construct(
        private readonly AgentClient $agentClient,
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

        $response = $this->agentClient->request($node, 'POST', '/v1/ts6/virtual-servers', $payload);
        $sid = (int) ($response['sid'] ?? 0);

        if ($sid <= 0) {
            throw new AgentBadResponseException('Agent did not return a virtual server id.');
        }

        $virtualServer = new Ts6VirtualServer($node, $customerId, $sid, $dto->name, $dto->slots);
        $virtualServer->setVoicePort(isset($response['voice_port']) ? (int) $response['voice_port'] : $dto->voicePort);
        $virtualServer->setFiletransferPort(isset($response['filetransfer_port']) ? (int) $response['filetransfer_port'] : null);
        $virtualServer->setStatus('running');
        $this->entityManager->persist($virtualServer);

        $token = $this->createOwnerToken($virtualServer);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $virtualServer;
    }

    public function start(Ts6VirtualServer $server): void
    {
        $this->applyServerAction($server, sprintf('/v1/ts6/virtual-servers/%d/start', $server->getSid()), 'running');
    }

    public function stop(Ts6VirtualServer $server): void
    {
        $this->applyServerAction($server, sprintf('/v1/ts6/virtual-servers/%d/stop', $server->getSid()), 'stopped');
    }

    public function recreate(Ts6VirtualServer $server): Ts6VirtualServer
    {
        $this->stop($server);
        $this->agentClient->request($server->getNode(), 'DELETE', sprintf('/v1/ts6/virtual-servers/%d', $server->getSid()));
        $server->archive();

        $dto = new CreateVirtualServerDto($server->getName(), $server->getSlots(), $server->getVoicePort());
        $replacement = $this->createForCustomer($server->getCustomerId(), $server->getNode(), $dto);

        $this->entityManager->flush();

        return $replacement;
    }

    public function rotateToken(Ts6VirtualServer $server): Ts6Token
    {
        $response = $this->agentClient->request(
            $server->getNode(),
            'POST',
            sprintf('/v1/ts6/virtual-servers/%d/tokens/rotate', $server->getSid()),
        );

        $tokenValue = (string) ($response['token'] ?? '');
        if ($tokenValue === '') {
            throw new AgentBadResponseException('Agent did not return a token.');
        }

        $existing = $this->findActiveToken($server);
        if ($existing !== null) {
            $existing->deactivate();
        }

        $token = new Ts6Token($server, $this->crypto->encrypt($tokenValue), 'owner');
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    private function applyServerAction(Ts6VirtualServer $server, string $endpoint, string $status): void
    {
        try {
            $this->agentClient->request($server->getNode(), 'POST', $endpoint);
            $server->setStatus($status);
        } catch (\Throwable $exception) {
            $server->setStatus('unknown');
        }

        $this->entityManager->flush();
    }

    private function createOwnerToken(Ts6VirtualServer $server): Ts6Token
    {
        $response = $this->agentClient->request(
            $server->getNode(),
            'POST',
            sprintf('/v1/ts6/virtual-servers/%d/tokens', $server->getSid()),
            ['type' => 'owner'],
        );

        $tokenValue = (string) ($response['token'] ?? '');
        if ($tokenValue === '') {
            throw new AgentBadResponseException('Agent did not return a token.');
        }

        return new Ts6Token($server, $this->crypto->encrypt($tokenValue), 'owner');
    }

    private function findActiveToken(Ts6VirtualServer $server): ?Ts6Token
    {
        $repository = $this->entityManager->getRepository(Ts6Token::class);

        return $repository->findOneBy(['virtualServer' => $server, 'active' => true]);
    }
}
