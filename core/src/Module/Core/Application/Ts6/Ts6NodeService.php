<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ts6;

use App\Module\Core\Dto\Ts6\InstallDto;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\EntityManagerInterface;

final class Ts6NodeService
{
    public function __construct(
        private readonly AgentClient $agentClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
    ) {
    }

    public function install(Ts6Node $node, InstallDto $dto): void
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
                    'voice_ip' => $dto->voiceIp,
                    'default_voice_port' => $dto->defaultVoicePort,
                    'filetransfer_port' => $dto->filetransferPort,
                    'filetransfer_ip' => $dto->filetransferIp,
                    'query' => [
                        'https_enable' => $dto->queryHttpsEnable,
                        'https_bind_ip' => $dto->queryBindIp,
                        'https_port' => $dto->queryHttpsPort,
                        'admin_password' => $dto->adminPassword,
                    ],
                ],
            ];

            $response = $this->agentClient->request($node, 'POST', '/v1/ts6/install', $payload);

            $node->setInstalledVersion($this->stringOrNull($response['installed_version'] ?? null));
            $node->setInstallStatus('installed');
            if (isset($response['query']) && is_array($response['query'])) {
                $node->setQueryBindIp((string) ($response['query']['https_bind_ip'] ?? $node->getQueryBindIp()));
                $node->setQueryHttpsPort((int) ($response['query']['https_port'] ?? $node->getQueryHttpsPort()));
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

    public function refreshStatus(Ts6Node $node): void
    {
        try {
            $status = $this->agentClient->request($node, 'GET', '/v1/ts6/status');
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

    public function start(Ts6Node $node): void
    {
        $this->applyServiceAction($node, '/v1/ts6/start');
    }

    public function stop(Ts6Node $node): void
    {
        $this->applyServiceAction($node, '/v1/ts6/stop');
    }

    public function restart(Ts6Node $node): void
    {
        $this->applyServiceAction($node, '/v1/ts6/restart');
    }

    private function applyServiceAction(Ts6Node $node, string $endpoint): void
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
