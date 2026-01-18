<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Sinusbot;

use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;

final class SinusbotNodeService
{
    public function __construct(
        private readonly \App\Module\AgentOrchestrator\Application\AgentJobDispatcher $jobDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
    ) {
    }

    public function install(SinusbotNode $node, bool $installTs3Client, ?string $ts3ClientDownloadUrl): void
    {
        $node->setInstallStatus('installing');
        $node->setLastError(null);
        $this->entityManager->flush();

        $payload = [
            'node_id' => $node->getId(),
            'download_url' => $node->getDownloadUrl(),
            'install_dir' => $node->getInstallPath(),
            'instance_root' => $node->getInstanceRoot(),
            'web_bind_ip' => $node->getWebBindIp(),
            'web_port_base' => $node->getWebPortBase(),
            'service_name' => 'sinusbot',
            'admin_username' => $node->getAdminUsername(),
            'admin_password' => $installTs3Client ? $this->crypto->decrypt($node->getAdminPasswordEncrypted()) : null,
            'ts3_client_install' => $installTs3Client,
            'ts3_client_download_url' => $ts3ClientDownloadUrl,
        ];

        $this->jobDispatcher->dispatch($node->getAgent(), 'sinusbot.install', $payload);

        $this->entityManager->flush();
    }

    public function refreshStatus(SinusbotNode $node): void
    {
        $payload = [
            'node_id' => $node->getId(),
            'service_name' => 'sinusbot',
        ];
        $this->jobDispatcher->dispatch($node->getAgent(), 'sinusbot.status', $payload);

        $this->entityManager->flush();
    }

    private function applyDependencyStatus(SinusbotNode $node, mixed $dependencies): void
    {
        if (!is_array($dependencies)) {
            return;
        }

        $node->setTs3ClientInstalled((bool) ($dependencies['ts3_client_installed'] ?? $node->isTs3ClientInstalled()));
        $node->setTs3ClientVersion($this->stringOrNull($dependencies['ts3_client_version'] ?? null));
        $node->setTs3ClientPath($this->stringOrNull($dependencies['ts3_client_path'] ?? null));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
