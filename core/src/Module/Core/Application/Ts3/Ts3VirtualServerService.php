<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts3;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts3Token;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Dto\Ts3\CreateVirtualServerDto;
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
            'query_bind_ip' => $node->getQueryConnectIp(),
            'query_port' => $node->getQueryPort(),
            'admin_username' => $node->getAdminUsername(),
            'admin_password' => $node->getAdminPassword($this->crypto),
        ];

        $this->jobDispatcher->dispatch($node->getAgent(), 'ts3.virtual.create', $jobPayload);
        $this->entityManager->flush();

        return $virtualServer;
    }

    public function start(Ts3VirtualServer $server): AgentJob
    {
        return $this->applyServerAction($server, 'start');
    }

    public function stop(Ts3VirtualServer $server): AgentJob
    {
        return $this->applyServerAction($server, 'stop');
    }

    public function restart(Ts3VirtualServer $server): AgentJob
    {
        return $this->applyServerAction($server, 'restart');
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

    public function rotateToken(Ts3VirtualServer $server, int $serverGroupId = 6): AgentJob
    {
        if ($serverGroupId <= 0) {
            $serverGroupId = 6;
        }
        $tokenType = sprintf('server_group:%d', $serverGroupId);
        $jobPayload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'server_group_id' => $serverGroupId,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];

        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.token.rotate', $jobPayload);

        $token = new Ts3Token($server, $this->crypto->encrypt('pending'), $tokenType);
        $token->deactivate();
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $job;
    }

    public function delete(Ts3VirtualServer $server): void
    {
        $server->setStatus('deleting');
        $this->applyServerAction($server, 'delete');
    }

    public function queueServerGroupList(Ts3VirtualServer $server, string $cacheKey): AgentJob
    {
        $jobPayload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'cache_key' => $cacheKey,
            'install_dir' => $server->getNode()->getInstallPath(),
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];

        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.servergroup.list', $jobPayload);
        $this->entityManager->flush();
        return $job;
    }

    public function queueServerSummary(Ts3VirtualServer $server, string $cacheKey): AgentJob
    {
        $jobPayload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'cache_key' => $cacheKey,
            'install_dir' => $server->getNode()->getInstallPath(),
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];

        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.summary', $jobPayload);
        $this->entityManager->flush();
        return $job;
    }

    public function kickClient(Ts3VirtualServer $server, int $clid, string $reason = ''): AgentJob
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'clid' => $clid,
            'reason' => $reason,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];
        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.client.kick', $payload);
        $this->entityManager->flush();
        return $job;
    }

    public function pokeClient(Ts3VirtualServer $server, int $clid, string $message): AgentJob
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'clid' => $clid,
            'message' => $message,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];
        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.client.poke', $payload);
        $this->entityManager->flush();
        return $job;
    }

    public function addBan(Ts3VirtualServer $server, array $banParams): AgentJob
    {
        $payload = array_merge([
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ], $banParams);
        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.ban.add', $payload);
        $this->entityManager->flush();
        return $job;
    }

    public function banClient(Ts3VirtualServer $server, int $clid, string $reason = 'Banned via panel', int $duration = 0): AgentJob
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'clid' => $clid,
            'reason' => $reason,
            'duration' => max(0, $duration),
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];
        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.client.ban', $payload);
        $this->entityManager->flush();
        return $job;
    }

    public function removeBan(Ts3VirtualServer $server, int $banid): AgentJob
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'banid' => $banid,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];
        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.ban.remove', $payload);
        $this->entityManager->flush();
        return $job;
    }

    public function queueSnapshot(Ts3VirtualServer $server, string $cacheKey): AgentJob
    {
        return $this->queueServerQuery($server, $cacheKey, 'ts3.virtual.snapshot.create');
    }

    public function queueSnapshotRestore(Ts3VirtualServer $server, string $snapshotContent): AgentJob
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'snapshot_content' => $snapshotContent,
            'install_dir' => $server->getNode()->getInstallPath(),
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];
        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.snapshot.restore', $payload);
        $this->entityManager->flush();
        return $job;
    }

    public function queueServerQuery(Ts3VirtualServer $server, string $cacheKey, string $jobType): AgentJob
    {
        $jobPayload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'cache_key' => $cacheKey,
            'install_dir' => $server->getNode()->getInstallPath(),
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];

        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), $jobType, $jobPayload);
        $this->entityManager->flush();
        return $job;
    }

    private function applyServerAction(Ts3VirtualServer $server, string $action): AgentJob
    {
        $payload = [
            'virtual_server_id' => $server->getId(),
            'node_id' => $server->getNode()->getId(),
            'sid' => $server->getSid(),
            'action' => $action,
            'query_bind_ip' => $server->getNode()->getQueryConnectIp(),
            'query_port' => $server->getNode()->getQueryPort(),
            'admin_username' => $server->getNode()->getAdminUsername(),
            'admin_password' => $server->getNode()->getAdminPassword($this->crypto),
        ];
        $job = $this->jobDispatcher->dispatch($server->getNode()->getAgent(), 'ts3.virtual.action', $payload);
        $this->entityManager->flush();
        return $job;
    }

}
