<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts6;

use App\Module\Core\Dto\Ts6\InstallDto;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Ts6Node;
use Doctrine\ORM\EntityManagerInterface;

final class Ts6NodeService
{
    public function __construct(
        private readonly \App\Module\AgentOrchestrator\Application\AgentJobDispatcher $jobDispatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function install(Ts6Node $node, InstallDto $dto): void
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
            'voice_ip' => $dto->voiceIp,
            'default_voice_port' => $dto->defaultVoicePort,
            'filetransfer_port' => $dto->filetransferPort,
            'filetransfer_ip' => $dto->filetransferIp,
            'query_https_enable' => $dto->queryHttpsEnable,
            'query_bind_ip' => $dto->queryBindIp,
            'query_https_port' => $dto->queryHttpsPort,
            'admin_password' => $dto->adminPassword,
        ];

        $this->queueFirewallPorts($node, $dto);
        $job = $this->jobDispatcher->dispatchWithFailureLogging($node->getAgent(), 'ts6.install', $payload);
        if ($job->getStatus()->value === 'failed') {
            $node->setInstallStatus('failed');
            $node->setLastError($job->getErrorText());
        }

        $this->entityManager->flush();
    }

    public function refreshStatus(Ts6Node $node): void
    {
        $payload = [
            'node_id' => $node->getId(),
            'service_name' => $node->getServiceName(),
            'install_dir' => $node->getInstallPath(),
            'download_url' => $node->getDownloadUrl(),
        ];
        $this->jobDispatcher->dispatch($node->getAgent(), 'ts6.status', $payload);

        $this->entityManager->flush();
    }

    public function start(Ts6Node $node): void
    {
        $this->applyServiceAction($node, 'start');
    }

    public function stop(Ts6Node $node): void
    {
        $this->applyServiceAction($node, 'stop');
    }

    public function restart(Ts6Node $node): void
    {
        $this->applyServiceAction($node, 'restart');
    }

    private function applyServiceAction(Ts6Node $node, string $action): void
    {
        $payload = [
            'node_id' => $node->getId(),
            'service_name' => $node->getServiceName(),
            'action' => $action,
        ];
        $this->jobDispatcher->dispatch($node->getAgent(), 'ts6.service.action', $payload);
        $this->entityManager->flush();
    }

    private function queueFirewallPorts(Ts6Node $node, InstallDto $dto): ?Job
    {
        $ports = [];
        if ($dto->defaultVoicePort > 0) {
            $ports[] = $dto->defaultVoicePort;
        }
        if ($dto->queryHttpsEnable && $dto->queryHttpsPort > 0) {
            $ports[] = $dto->queryHttpsPort;
        }

        $ports = array_values(array_unique(array_filter($ports, static fn (int $port): bool => $port > 0 && $port <= 65535)));
        if ($ports === []) {
            return null;
        }

        $job = new Job('firewall.open_ports', [
            'agent_id' => $node->getAgent()->getId(),
            'ts6_node_id' => (string) $node->getId(),
            'ports' => implode(',', array_map('strval', $ports)),
        ]);
        $this->entityManager->persist($job);

        return $job;
    }
}
