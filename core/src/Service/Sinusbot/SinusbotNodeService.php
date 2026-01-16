<?php

declare(strict_types=1);

namespace App\Service\Sinusbot;

use App\Entity\SinusbotNode;
use App\Service\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;

final class SinusbotNodeService
{
    public function __construct(
        private readonly AgentClient $agentClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
    ) {
    }

    public function install(SinusbotNode $node, bool $installTs3Client, ?string $ts3ClientDownloadUrl): void
    {
        $node->setInstallStatus('installing');
        $node->setLastError(null);
        $this->entityManager->flush();

        try {
            $payload = [
                'download_url' => $node->getDownloadUrl(),
                'install_path' => $node->getInstallPath(),
                'instance_root' => $node->getInstanceRoot(),
                'web_bind_ip' => $node->getWebBindIp(),
                'web_port_base' => $node->getWebPortBase(),
                'return_admin_credentials' => true,
            ];

            if ($installTs3Client || $ts3ClientDownloadUrl !== null) {
                $payload['dependencies'] = [
                    'install_ts3_client' => $installTs3Client,
                ];

                if ($ts3ClientDownloadUrl !== null && $ts3ClientDownloadUrl !== '') {
                    $payload['dependencies']['ts3_client_download_url'] = $ts3ClientDownloadUrl;
                }
            }

            $response = $this->agentClient->request($node, 'POST', '/v1/sinusbot/install', $payload);

            $node->setInstalledVersion($this->stringOrNull($response['installed_version'] ?? null));
            $node->setInstallStatus('installed');
            $node->setLastError($this->stringOrNull($response['last_error'] ?? null));

            if (isset($response['admin_credentials']) && is_array($response['admin_credentials'])) {
                $username = (string) ($response['admin_credentials']['username'] ?? '');
                $password = $response['admin_credentials']['password'] ?? null;
                if ($username !== '') {
                    $node->setAdminUsername($username);
                }
                if (is_string($password) && $password !== '') {
                    $node->setAdminPassword($password, $this->crypto);
                }
            }

            $this->applyDependencyStatus($node, $response['dependencies'] ?? null);

            $this->refreshStatus($node);
        } catch (\Throwable $exception) {
            $node->setInstallStatus('error');
            $node->setLastError($exception->getMessage());
        }

        $this->entityManager->flush();
    }

    public function refreshStatus(SinusbotNode $node): void
    {
        try {
            $status = $this->agentClient->request($node, 'GET', '/v1/sinusbot/status');
            $installed = (bool) ($status['installed'] ?? false);
            $node->setInstallStatus($installed ? 'installed' : 'not_installed');
            $node->setInstalledVersion($this->stringOrNull($status['installed_version'] ?? null));
            $node->setLastError($this->stringOrNull($status['last_error'] ?? null));
            $this->applyDependencyStatus($node, $status['dependencies'] ?? null);
        } catch (\Throwable $exception) {
            $node->setInstallStatus('error');
            $node->setLastError($exception->getMessage());
        }

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
