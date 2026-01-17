<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts3;

use App\Module\Core\Dto\Ts3\InstallDto;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;

final class Ts3NodeService
{
    public function __construct(
        private readonly AgentClient $agentClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
    ) {
    }

    public function install(Ts3Node $node, InstallDto $dto): void
    {
        $node->setInstallStatus('installing');
        $node->setLastError(null);
        $this->entityManager->flush();

        try {
            $payload = [
                'download_url' => $dto->downloadUrl,
                'install_path' => $dto->installPath,
                'instance_name' => $dto->instanceName,
                'service_name' => $dto->serviceName,
                'return_admin_credentials' => true,
                'config' => [
                    'accept_license' => $dto->acceptLicense,
                    'query' => [
                        'bind_ip' => $dto->queryBindIp,
                        'port' => $dto->queryPort,
                    ],
                    'admin_password' => $dto->adminPassword,
                ],
            ];

            $response = $this->agentClient->request($node, 'POST', '/v1/ts3/install', $payload);

            $node->setInstalledVersion($this->stringOrNull($response['installed_version'] ?? null));
            $node->setInstallStatus('installed');
            if (isset($response['query']) && is_array($response['query'])) {
                $node->setQueryBindIp((string) ($response['query']['bind_ip'] ?? $node->getQueryBindIp()));
                $node->setQueryPort((int) ($response['query']['port'] ?? $node->getQueryPort()));
            }

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

            $this->refreshStatus($node);
        } catch (\Throwable $exception) {
            $node->setInstallStatus('error');
            $node->setLastError($exception->getMessage());
        }

        $this->entityManager->flush();
    }

    public function refreshStatus(Ts3Node $node): void
    {
        try {
            $status = $this->agentClient->request($node, 'GET', '/v1/ts3/status');
            $installed = (bool) ($status['installed'] ?? false);
            $node->setInstallStatus($installed ? 'installed' : 'not_installed');
            $node->setInstalledVersion($this->stringOrNull($status['installed_version'] ?? null));
            $node->setRunning((bool) ($status['running'] ?? false));
            $node->setLastError($this->stringOrNull($status['last_error'] ?? null));
        } catch (\Throwable $exception) {
            $node->setInstallStatus('error');
            $node->setLastError($exception->getMessage());
        }

        $this->entityManager->flush();
    }

    public function start(Ts3Node $node): void
    {
        $this->applyServiceAction($node, '/v1/ts3/start');
    }

    public function stop(Ts3Node $node): void
    {
        $this->applyServiceAction($node, '/v1/ts3/stop');
    }

    public function restart(Ts3Node $node): void
    {
        $this->applyServiceAction($node, '/v1/ts3/restart');
    }

    private function applyServiceAction(Ts3Node $node, string $endpoint): void
    {
        try {
            $status = $this->agentClient->request($node, 'POST', $endpoint);
            $node->setRunning((bool) ($status['running'] ?? $node->isRunning()));
            $node->setLastError($this->stringOrNull($status['last_error'] ?? null));
        } catch (\Throwable $exception) {
            $node->setLastError($exception->getMessage());
        }

        $this->entityManager->flush();
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
