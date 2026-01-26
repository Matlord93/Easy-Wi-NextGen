<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\Application;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts3Token;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Entity\Ts6Token;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Enum\Ts3InstanceStatus;
use App\Module\Core\Domain\Enum\Ts6InstanceStatus;
use App\Module\Core\Application\SecretsCrypto;
use App\Repository\SinusbotNodeRepository;
use App\Repository\Ts3InstanceRepository;
use App\Repository\Ts3NodeRepository;
use App\Repository\Ts3VirtualServerRepository;
use App\Repository\Ts6InstanceRepository;
use App\Repository\Ts6NodeRepository;
use App\Repository\Ts6VirtualServerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AgentJobResultApplier
{
    public function __construct(
        private readonly Ts3NodeRepository $ts3NodeRepository,
        private readonly Ts6NodeRepository $ts6NodeRepository,
        private readonly SinusbotNodeRepository $sinusbotNodeRepository,
        private readonly Ts3InstanceRepository $ts3InstanceRepository,
        private readonly Ts6InstanceRepository $ts6InstanceRepository,
        private readonly Ts3VirtualServerRepository $ts3VirtualServerRepository,
        private readonly Ts6VirtualServerRepository $ts6VirtualServerRepository,
        private readonly SecretsCrypto $crypto,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function apply(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $type = $job->getType();

        if (str_starts_with($type, 'ts3.') && str_contains($type, 'instance')) {
            $this->applyTs3InstanceResult($job, $status);
        }

        if (str_starts_with($type, 'ts6.instance')) {
            $this->applyTs6InstanceResult($job, $status);
        }

        if (str_starts_with($type, 'ts3.') && (str_contains($type, 'service') || $type === 'ts3.install')) {
            $this->applyTs3NodeResult($job, $status, $payload);
        }

        if (str_starts_with($type, 'ts6.') && (str_contains($type, 'service') || $type === 'ts6.install')) {
            $this->applyTs6NodeResult($job, $status, $payload);
        }

        if (str_starts_with($type, 'ts3.virtual')) {
            $this->applyTs3VirtualServerResult($job, $status, $payload);
        }

        if (str_starts_with($type, 'ts6.virtual')) {
            $this->applyTs6VirtualServerResult($job, $status, $payload);
        }

        if (str_starts_with($type, 'sinusbot.')) {
            $this->applySinusbotNodeResult($job, $status, $payload);
        }

        if ($type === 'admin.ssh_key.store') {
            $this->applyAdminSshKeyResult($job, $status);
        }

        $this->entityManager->flush();
    }

    private function applyTs3NodeResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $nodeId = $job->getPayload()['node_id'] ?? null;
        if (!is_int($nodeId) && !is_string($nodeId)) {
            return;
        }
        $node = $this->ts3NodeRepository->find((int) $nodeId);
        if (!$node instanceof Ts3Node) {
            return;
        }

        if ($job->getType() === 'ts3.install') {
            if ($status === AgentJobStatus::Success) {
                $node->setInstallStatus('installed');
            } elseif ($status === AgentJobStatus::Failed) {
                $node->setInstallStatus('error');
            }
            if (is_array($payload) && isset($payload['installed_version']) && is_string($payload['installed_version'])) {
                $node->setInstalledVersion($payload['installed_version']);
            }
            if (is_array($payload) && array_key_exists('running', $payload)) {
                $node->setRunning((bool) $payload['running']);
            }
        }

        if (in_array($job->getType(), ['ts3.service.action', 'ts3.status'], true)) {
            if (is_array($payload) && array_key_exists('running', $payload)) {
                $node->setRunning((bool) $payload['running']);
            }
            if (is_array($payload) && isset($payload['installed_version']) && is_string($payload['installed_version'])) {
                $node->setInstalledVersion($payload['installed_version']);
            }
        }

        if (is_array($payload) && isset($payload['last_error']) && is_string($payload['last_error'])) {
            $node->setLastError($payload['last_error']);
        }

        if (is_array($payload) && isset($payload['dependencies']) && is_array($payload['dependencies'])) {
            $dependencies = $payload['dependencies'];
            $node->setTs3ClientInstalled((bool) ($dependencies['ts3_client_installed'] ?? $node->isTs3ClientInstalled()));
            $node->setTs3ClientVersion(is_string($dependencies['ts3_client_version'] ?? null) ? $dependencies['ts3_client_version'] : null);
            $node->setTs3ClientPath(is_string($dependencies['ts3_client_path'] ?? null) ? $dependencies['ts3_client_path'] : null);
        }
    }

    private function applyTs6NodeResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $nodeId = $job->getPayload()['node_id'] ?? null;
        if (!is_int($nodeId) && !is_string($nodeId)) {
            return;
        }
        $node = $this->ts6NodeRepository->find((int) $nodeId);
        if (!$node instanceof Ts6Node) {
            return;
        }

        if ($job->getType() === 'ts6.install') {
            if ($status === AgentJobStatus::Success) {
                $node->setInstallStatus('installed');
            } elseif ($status === AgentJobStatus::Failed) {
                $node->setInstallStatus('error');
            }
            if (is_array($payload) && isset($payload['installed_version']) && is_string($payload['installed_version'])) {
                $node->setInstalledVersion($payload['installed_version']);
            }
            if (is_array($payload) && array_key_exists('running', $payload)) {
                $node->setRunning((bool) $payload['running']);
            }
        }

        if (in_array($job->getType(), ['ts6.service.action', 'ts6.status'], true)) {
            if (is_array($payload) && array_key_exists('running', $payload)) {
                $node->setRunning((bool) $payload['running']);
            }
            if (is_array($payload) && isset($payload['installed_version']) && is_string($payload['installed_version'])) {
                $node->setInstalledVersion($payload['installed_version']);
            }
            if ($status === AgentJobStatus::Success && is_array($payload) && array_key_exists('running', $payload)) {
                $this->applyInstallStatusFromRuntime($node, (bool) $payload['running']);
            }
        }

        if (is_array($payload) && isset($payload['last_error']) && is_string($payload['last_error'])) {
            $node->setLastError($payload['last_error']);
        }
    }

    private function applySinusbotNodeResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $nodeId = $job->getPayload()['node_id'] ?? null;
        if (!is_int($nodeId) && !is_string($nodeId)) {
            return;
        }
        $node = $this->sinusbotNodeRepository->find((int) $nodeId);
        if (!$node instanceof SinusbotNode) {
            return;
        }

        if ($job->getType() === 'sinusbot.install') {
            if ($status === AgentJobStatus::Success) {
                $node->setInstallStatus('installed');
            } elseif ($status === AgentJobStatus::Failed) {
                $node->setInstallStatus('error');
            }
            if (is_array($payload) && isset($payload['installed_version']) && is_string($payload['installed_version'])) {
                $node->setInstalledVersion($payload['installed_version']);
            }
            if (is_array($payload) && array_key_exists('running', $payload)) {
                $node->setRunning((bool) $payload['running']);
            }
        }

        if (in_array($job->getType(), ['sinusbot.status', 'sinusbot.service.action'], true)) {
            if (is_array($payload) && array_key_exists('running', $payload)) {
                $node->setRunning((bool) $payload['running']);
            }
            if (is_array($payload) && isset($payload['installed_version']) && is_string($payload['installed_version'])) {
                $node->setInstalledVersion($payload['installed_version']);
            }
            if ($status === AgentJobStatus::Success && is_array($payload) && array_key_exists('running', $payload)) {
                $this->applyInstallStatusFromRuntime($node, (bool) $payload['running']);
            }
        }

        if (is_array($payload) && isset($payload['last_error']) && is_string($payload['last_error'])) {
            $node->setLastError($payload['last_error']);
        }
    }

    private function applyInstallStatusFromRuntime(Ts6Node|SinusbotNode $node, bool $running): void
    {
        if ($running && $node->getInstallStatus() !== 'installed') {
            $node->setInstallStatus('installed');
        }
    }

    private function applyAdminSshKeyResult(AgentJob $job, AgentJobStatus $status): void
    {
        $userId = $job->getPayload()['user_id'] ?? null;
        $publicKey = $job->getPayload()['public_key'] ?? null;

        if (!is_int($userId) && !is_string($userId)) {
            return;
        }

        if (!is_string($publicKey) || trim($publicKey) === '') {
            return;
        }

        $user = $this->userRepository->find((int) $userId);
        if ($user === null) {
            return;
        }

        $pending = $user->getAdminSshPublicKeyPending();
        if ($pending !== null && trim($pending) !== trim($publicKey)) {
            return;
        }

        if ($status === AgentJobStatus::Success) {
            $user->setAdminSshPublicKey($publicKey);
            $user->setAdminSshPublicKeyPending(null);
        }
    }

    private function applyTs3VirtualServerResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $virtualId = $job->getPayload()['virtual_server_id'] ?? null;
        if (!is_int($virtualId) && !is_string($virtualId)) {
            return;
        }
        $server = $this->ts3VirtualServerRepository->find((int) $virtualId);
        if (!$server instanceof Ts3VirtualServer) {
            return;
        }

        if ($status === AgentJobStatus::Failed) {
            $server->setStatus('error');
            return;
        }

        if ($job->getType() === 'ts3.virtual.create' && is_array($payload)) {
            if (isset($payload['sid'])) {
                $server->setSid((int) $payload['sid']);
            }
            if (isset($payload['voice_port'])) {
                $server->setVoicePort((int) $payload['voice_port']);
            }
            if (isset($payload['filetransfer_port'])) {
                $server->setFiletransferPort((int) $payload['filetransfer_port']);
            }
            $server->setStatus('running');
            $this->applyVirtualToken($server, Ts3Token::class, $payload['token'] ?? null);
        }

        if ($job->getType() === 'ts3.virtual.action') {
            $action = (string) ($job->getPayload()['action'] ?? '');
            $server->setStatus($action === 'stop' ? 'stopped' : 'running');
        }

        if ($job->getType() === 'ts3.virtual.token.rotate' && is_array($payload)) {
            $this->applyVirtualToken($server, Ts3Token::class, $payload['token'] ?? null);
        }
    }

    private function applyTs6VirtualServerResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $virtualId = $job->getPayload()['virtual_server_id'] ?? null;
        if (!is_int($virtualId) && !is_string($virtualId)) {
            return;
        }
        $server = $this->ts6VirtualServerRepository->find((int) $virtualId);
        if (!$server instanceof Ts6VirtualServer) {
            return;
        }

        if ($status === AgentJobStatus::Failed) {
            $server->setStatus('error');
            return;
        }

        if ($job->getType() === 'ts6.virtual.create' && is_array($payload)) {
            if (isset($payload['sid'])) {
                $server->setSid((int) $payload['sid']);
            }
            if (isset($payload['voice_port'])) {
                $server->setVoicePort((int) $payload['voice_port']);
            }
            if (isset($payload['filetransfer_port'])) {
                $server->setFiletransferPort((int) $payload['filetransfer_port']);
            }
            $server->setStatus('running');
            $this->applyVirtualToken($server, Ts6Token::class, $payload['token'] ?? null);
        }

        if ($job->getType() === 'ts6.virtual.action') {
            $action = (string) ($job->getPayload()['action'] ?? '');
            $server->setStatus($action === 'stop' ? 'stopped' : 'running');
        }

        if ($job->getType() === 'ts6.virtual.token.rotate' && is_array($payload)) {
            $this->applyVirtualToken($server, Ts6Token::class, $payload['token'] ?? null);
        }
    }

    private function applyTs3InstanceResult(AgentJob $job, AgentJobStatus $status): void
    {
        $instanceId = $job->getPayload()['instance_id'] ?? $job->getPayload()['ts3_instance_id'] ?? null;
        if (!is_int($instanceId) && !is_string($instanceId)) {
            return;
        }
        $instance = $this->ts3InstanceRepository->find((int) $instanceId);
        if ($instance === null) {
            return;
        }

        if ($status === AgentJobStatus::Failed) {
            $instance->setStatus(Ts3InstanceStatus::Error);
            return;
        }

        if ($status !== AgentJobStatus::Success) {
            return;
        }

        $newStatus = match ($job->getType()) {
            'ts3.instance.create' => Ts3InstanceStatus::Running,
            'ts3.instance.action' => $this->resolveInstanceActionStatus($job, Ts3InstanceStatus::Running, Ts3InstanceStatus::Stopped),
            default => null,
        };

        if ($newStatus !== null) {
            $instance->setStatus($newStatus);
        }
    }

    private function applyTs6InstanceResult(AgentJob $job, AgentJobStatus $status): void
    {
        $instanceId = $job->getPayload()['instance_id'] ?? $job->getPayload()['ts6_instance_id'] ?? null;
        if (!is_int($instanceId) && !is_string($instanceId)) {
            return;
        }
        $instance = $this->ts6InstanceRepository->find((int) $instanceId);
        if ($instance === null) {
            return;
        }

        if ($status === AgentJobStatus::Failed) {
            $instance->setStatus(Ts6InstanceStatus::Error);
            return;
        }

        if ($status !== AgentJobStatus::Success) {
            return;
        }

        $newStatus = match ($job->getType()) {
            'ts6.instance.create' => Ts6InstanceStatus::Running,
            'ts6.instance.action' => $this->resolveInstanceActionStatus($job, Ts6InstanceStatus::Running, Ts6InstanceStatus::Stopped),
            default => null,
        };

        if ($newStatus !== null) {
            $instance->setStatus($newStatus);
        }
    }

    private function resolveInstanceActionStatus(AgentJob $job, Ts3InstanceStatus|Ts6InstanceStatus $running, Ts3InstanceStatus|Ts6InstanceStatus $stopped): Ts3InstanceStatus|Ts6InstanceStatus
    {
        $action = strtolower((string) ($job->getPayload()['action'] ?? ''));
        if ($action === 'stop') {
            return $stopped;
        }

        return $running;
    }

    /**
     * @param class-string $tokenClass
     */
    private function applyVirtualToken(Ts3VirtualServer|Ts6VirtualServer $server, string $tokenClass, mixed $tokenValue): void
    {
        if (!is_string($tokenValue) || $tokenValue === '') {
            return;
        }

        $existing = $this->entityManager->getRepository($tokenClass)->findOneBy([
            'virtualServer' => $server,
            'active' => true,
        ]);
        if ($existing instanceof Ts3Token || $existing instanceof Ts6Token) {
            $existing->deactivate();
        }

        if ($tokenClass === Ts3Token::class) {
            $token = new Ts3Token($server, $this->crypto->encrypt($tokenValue), 'owner');
        } else {
            $token = new Ts6Token($server, $this->crypto->encrypt($tokenValue), 'owner');
        }
        $this->entityManager->persist($token);
    }
}
