<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Sinusbot;

use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;

final class SinusbotNodeService
{
    private const DEFAULT_TS3_CLIENT_DOWNLOAD_URL = 'https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run';

    public function __construct(
        private readonly \App\Module\AgentOrchestrator\Application\AgentJobDispatcher $jobDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
    ) {
    }

    public function install(SinusbotNode $node): void
    {
        $node->setInstallStatus('installing');
        $node->setLastError(null);

        $adminPassword = $node->getAdminPassword($this->crypto);
        if ($adminPassword === null) {
            $adminPassword = bin2hex(random_bytes(12));
            $node->setAdminPassword($adminPassword, $this->crypto);
        }
        if ($node->getAdminUsername() === null) {
            $node->setAdminUsername('admin');
        }

        $this->entityManager->flush();

        $payload = [
            'node_id' => $node->getId(),
            'download_url' => $node->getDownloadUrl(),
            'download_filename' => $this->resolveDownloadFilename($node->getDownloadUrl()),
            'install_dir' => $node->getInstallPath(),
            'install_path' => $node->getInstallPath(),
            'instance_root' => $node->getInstanceRoot(),
            'web_bind_ip' => $node->getWebBindIp(),
            'web_port_base' => $node->getWebPortBase(),
            'service_name' => 'sinusbot',
            'service_user' => 'sinusbot',
            'admin_username' => $node->getAdminUsername(),
            'admin_password' => $adminPassword,
            'return_admin_credentials' => true,
            'ts3_client_install' => true,
            'ts3_client_download_url' => self::DEFAULT_TS3_CLIENT_DOWNLOAD_URL,
        ];

        $job = $this->jobDispatcher->dispatchWithFailureLogging($node->getAgent(), 'sinusbot.install', $payload);
        if ($job->getStatus()->value === 'failed') {
            $node->setInstallStatus('failed');
            $node->setLastError($job->getErrorText());
        }

        $this->entityManager->flush();
    }

    public function refreshStatus(SinusbotNode $node): void
    {
        $payload = [
            'node_id' => $node->getId(),
            'service_name' => 'sinusbot',
            'install_dir' => $node->getInstallPath(),
            'download_url' => $node->getDownloadUrl(),
            'ts3_client_download_url' => self::DEFAULT_TS3_CLIENT_DOWNLOAD_URL,
        ];
        $this->jobDispatcher->dispatch($node->getAgent(), 'sinusbot.status', $payload);

        $this->entityManager->flush();
    }

    public function start(SinusbotNode $node): void
    {
        $this->applyServiceAction($node, 'start');
    }

    public function stop(SinusbotNode $node): void
    {
        $this->applyServiceAction($node, 'stop');
    }

    public function restart(SinusbotNode $node): void
    {
        $this->applyServiceAction($node, 'restart');
    }

    private function applyServiceAction(SinusbotNode $node, string $action): void
    {
        $payload = [
            'node_id' => $node->getId(),
            'service_name' => 'sinusbot',
            'action' => $action,
        ];
        $this->jobDispatcher->dispatch($node->getAgent(), 'sinusbot.service.action', $payload);
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

    private function resolveDownloadFilename(string $downloadUrl): ?string
    {
        $path = parse_url($downloadUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $basename = basename($path);

        return $basename !== '' ? $basename : null;
    }
}
