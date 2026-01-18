<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts3;

use App\Module\Core\Dto\Ts3\InstallDto;
use App\Module\Core\Domain\Entity\Ts3Node;
use Doctrine\ORM\EntityManagerInterface;

final class Ts3NodeService
{
    public function __construct(
        private readonly \App\Module\AgentOrchestrator\Application\AgentJobDispatcher $jobDispatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function install(Ts3Node $node, InstallDto $dto): void
    {
        $node->setInstallStatus('installing');
        $node->setLastError(null);
        $this->entityManager->flush();

        $payload = [
            'node_id' => $node->getId(),
            'download_url' => $dto->downloadUrl,
            'install_dir' => $dto->installPath,
            'instance_name' => $dto->instanceName,
            'service_name' => $dto->serviceName,
            'accept_license' => $dto->acceptLicense,
            'query_bind_ip' => $dto->queryBindIp,
            'query_port' => $dto->queryPort,
            'admin_password' => $dto->adminPassword,
            'voice_port' => 9987,
            'file_port' => 30033,
        ];

        $this->jobDispatcher->dispatch($node->getAgent(), 'ts3.install', $payload);

        $this->entityManager->flush();
    }

    public function refreshStatus(Ts3Node $node): void
    {
        $payload = [
            'node_id' => $node->getId(),
            'service_name' => $node->getServiceName(),
        ];
        $this->jobDispatcher->dispatch($node->getAgent(), 'ts3.status', $payload);

        $this->entityManager->flush();
    }

    public function start(Ts3Node $node): void
    {
        $this->applyServiceAction($node, 'start');
    }

    public function stop(Ts3Node $node): void
    {
        $this->applyServiceAction($node, 'stop');
    }

    public function restart(Ts3Node $node): void
    {
        $this->applyServiceAction($node, 'restart');
    }

    private function applyServiceAction(Ts3Node $node, string $action): void
    {
        $payload = [
            'node_id' => $node->getId(),
            'service_name' => $node->getServiceName(),
            'action' => $action,
        ];
        $this->jobDispatcher->dispatch($node->getAgent(), 'ts3.service.action', $payload);
        $this->entityManager->flush();
    }
}
