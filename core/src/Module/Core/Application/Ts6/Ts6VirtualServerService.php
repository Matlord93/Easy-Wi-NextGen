<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts6;

use App\Module\Core\Dto\Ts6\CreateVirtualServerDto;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Entity\Ts6Token;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\Job;
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
        $voicePort = $dto->voicePort ?? $node->getVoicePort();
        $params = ['slots' => $dto->slots];
        if ($voicePort !== null) {
            $params['voice_port'] = $voicePort;
        }

        $payload = [
            'name' => $dto->name,
            'params' => $params,
        ];

        $virtualServer = new Ts6VirtualServer($node, $customerId, 0, $dto->name, $dto->slots);
        $virtualServer->setVoicePort($voicePort);
        $virtualServer->setFiletransferPort(null);
        $virtualServer->setStatus('provisioning');
        $this->entityManager->persist($virtualServer);
        $this->entityManager->flush();

        if ($voicePort !== null && $voicePort > 0) {
            $firewallJob = new Job('firewall.open_ports', [
                'agent_id' => $node->getAgent()->getId(),
                'ts6_virtual_server_id' => (string) $virtualServer->getId(),
                'ports' => (string) $voicePort,
            ]);
            $this->entityManager->persist($firewallJob);
        }

        $jobPayload = [
            'virtual_server_id' => $virtualServer->getId(),
            'node_id' => $node->getId(),
            'name' => $dto->name,
            'params' => $params,
            'query_bind_ip' => $node->getQueryConnectIp(),
            'query_https_port' => $node->getQueryHttpsPort(),
            'install_dir' => $node->getInstallPath(),
            'admin_password' => $node->getAdminPassword($this->crypto),
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

    public function restart(Ts6VirtualServer $server): void
    {
        $this->applyServerAction($server, 'restart');
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

    public function rotateToken(Ts6VirtualServer $server, int $serverGroupId = 6): Ts6Token
    {
        if ($serverGroupId <= 0) {
            $serverGroupId = 6;
        }
        $tokenType = sprintf('server_group:%d', $serverGroupId);
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'server_group_id' => $serverGroupId,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_https_port' => $server->getNode()->getQueryHttpsPort(),
            'install_dir' => $server->getNode()->getInstallPath(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];
        $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts6.virtual.token.rotate', $payload);

        $token = new Ts6Token($server, $this->crypto->encrypt('pending'), $tokenType);
        $token->deactivate();
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    public function delete(Ts6VirtualServer $server): void
    {
        if ($server->getSid() <= 0) {
            $server->setStatus('deleted');
            $server->archive();
            $this->entityManager->flush();
            return;
        }

        $server->setStatus('deleting');
        $this->applyServerAction($server, 'delete');
    }

    public function queueVirtualServerSync(Ts6Node $node): AgentJob
    {
        $payload = [
            'node_id' => (string) ($node->getId() ?? ''),
            'query_bind_ip' => $node->getQueryConnectIp(),
            'query_https_port' => $node->getQueryHttpsPort(),
            'install_dir' => $node->getInstallPath(),
            'admin_username' => $node->getAdminUsername(),
            'admin_password' => $node->getAdminPassword($this->crypto),
        ];

        return $this->jobDispatcher->dispatch($node->getAgent(), 'ts6.virtual.list', $payload);
    }

    public function queueServerGroupList(Ts6VirtualServer $server, string $cacheKey): void
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'cache_key' => $cacheKey,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_https_port' => $server->getNode()->getQueryHttpsPort(),
            'install_dir' => $server->getNode()->getInstallPath(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];

        $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts6.virtual.servergroup.list', $payload);
        $this->entityManager->flush();
    }

    public function queueServerSummary(Ts6VirtualServer $server, string $cacheKey): void
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'cache_key' => $cacheKey,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_https_port' => $server->getNode()->getQueryHttpsPort(),
            'install_dir' => $server->getNode()->getInstallPath(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];

        $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts6.virtual.summary', $payload);
        $this->entityManager->flush();
    }

    public function queueServerQuery(Ts6VirtualServer $server, string $cacheKey, string $jobType): void
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'cache_key' => $cacheKey,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_https_port' => $server->getNode()->getQueryHttpsPort(),
            'install_dir' => $server->getNode()->getInstallPath(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];

        $this->jobDispatcher->dispatch($server->getNode()->getAgent(), $jobType, $payload);
        $this->entityManager->flush();
    }

    private function applyServerAction(Ts6VirtualServer $server, string $action): void
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'action' => $action,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_https_port' => $server->getNode()->getQueryHttpsPort(),
            'install_dir' => $server->getNode()->getInstallPath(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];
        $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts6.virtual.action', $payload);
        $this->entityManager->flush();
    }

}
