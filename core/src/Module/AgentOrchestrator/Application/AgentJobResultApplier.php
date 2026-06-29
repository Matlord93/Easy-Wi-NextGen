<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\Application;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\SinusbotNode;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\Ts3Token;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Entity\Ts6Token;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Enum\Ts3InstanceStatus;
use App\Module\Core\Domain\Enum\Ts6InstanceStatus;
use App\Module\Musicbot\Application\MusicbotRuntimeEventServiceInterface;
use App\Module\Musicbot\Application\MusicbotSecretConfigService;
use App\Module\Musicbot\Application\MusicbotPayloadLogSummarizer;
use App\Module\Musicbot\Application\MusicbotRuntimeStatusNormalizer;
use App\Module\Musicbot\Domain\Entity\MusicbotConnection;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotTeamspeakBackendConfig;
use App\Module\Musicbot\Domain\Enum\MusicbotConnectionStatus;
use App\Module\Musicbot\Domain\Enum\MusicbotInstanceStatus;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Repository\MusicbotConnectionRepository;
use App\Repository\MusicbotInstanceRepository;
use App\Repository\MusicbotInstanceRepositoryInterface;
use App\Repository\MusicbotTeamspeakBackendConfigRepository;
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
        private readonly MusicbotInstanceRepositoryInterface $musicbotInstanceRepository,
        private readonly MusicbotConnectionRepository $musicbotConnectionRepository,
        private readonly MusicbotTeamspeakBackendConfigRepository $musicbotTeamspeakBackendConfigRepository,
        private readonly Ts3InstanceRepository $ts3InstanceRepository,
        private readonly Ts6InstanceRepository $ts6InstanceRepository,
        private readonly Ts3VirtualServerRepository $ts3VirtualServerRepository,
        private readonly Ts6VirtualServerRepository $ts6VirtualServerRepository,
        private readonly SecretsCrypto $crypto,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotRuntimeEventServiceInterface $musicbotRuntimeEventService,
        private readonly MusicbotSecretConfigService $musicbotSecretConfigService,
        private readonly MusicbotRuntimeStatusNormalizer $musicbotRuntimeStatusNormalizer,
    ) {
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function apply(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $type = $job->getType();

        if ($type === 'ts3.instance.create' || $type === 'ts3.instance.action') {
            $this->applyTs3InstanceResult($job, $status);
        }

        if ($type === 'ts6.instance.create' || $type === 'ts6.instance.action') {
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

        if (str_starts_with($type, 'sinusbot.') && !in_array($type, ['sinusbot.instance.create', 'sinusbot.instance.action'], true)) {
            $this->applySinusbotNodeResult($job, $status, $payload);
        }

        if (str_starts_with($type, 'musicbot.')) {
            $this->debugMusicbotStatus('agent.result.received', $job, $payload);
            $this->applyMusicbotResult($job, $status, $payload);
        }

        if ($type === 'admin.ssh_key.store') {
            $this->applyAdminSshKeyResult($job, $status);
        }

        // core.ssh.policy.apply is a fire-and-forget job; no domain state to update on completion.

        $this->entityManager->flush();
    }


    private function applyMusicbotResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        if (str_starts_with($job->getType(), 'musicbot.teamspeak_backend.')) {
            $this->applyMusicbotTeamspeakBackendResult($job, $status, $payload);
            return;
        }

        if ($job->getType() === 'musicbot.connection.test') {
            $this->applyMusicbotConnectionTestResult($job, $status, $payload);
            return;
        }

        if ($job->getType() === 'musicbot.config.apply') {
            $this->applyMusicbotConfigApplyResult($job, $status, $payload);
            return;
        }

        $instance = $this->findMusicbotInstanceFromJob($job);
        if (!$instance instanceof MusicbotInstance) {
            return;
        }

        if ($job->getType() === 'musicbot.install') {
            if ($status === AgentJobStatus::Success) {
                $instance->setStatus(MusicbotInstanceStatus::Stopped);
                $instance->setLastError(null);
                $this->musicbotRuntimeEventService->record($instance, 'instance.installed', 'info', 'Musicbot installation completed.', ['job_id' => $job->getId()]);
            } elseif ($status === AgentJobStatus::Failed) {
                $error = $this->extractError($job, $payload);
                $instance->setStatus(MusicbotInstanceStatus::Error);
                $instance->setLastError($error);
                $this->musicbotRuntimeEventService->record($instance, 'runtime.error', 'error', 'Musicbot installation failed.', ['job_id' => $job->getId(), 'error' => $error]);
            }
        }

        if ($job->getType() === 'musicbot.uninstall') {
            if ($status === AgentJobStatus::Success) {
                $instance->setStatus(MusicbotInstanceStatus::Stopped);
                $instance->setLastError(null);
                $this->musicbotRuntimeEventService->record($instance, 'instance.uninstalled', 'info', 'Musicbot uninstall completed.', ['job_id' => $job->getId()]);
            } elseif ($status === AgentJobStatus::Failed) {
                $error = $this->extractError($job, $payload);
                $instance->setStatus(MusicbotInstanceStatus::Error);
                $instance->setLastError($error);
                $this->musicbotRuntimeEventService->record($instance, 'runtime.error', 'error', 'Musicbot uninstall failed.', ['job_id' => $job->getId(), 'error' => $error]);
            }
        }


        if ($job->getType() === 'musicbot.update' || $job->getType() === 'musicbot.repair') {
            $operation = $job->getType() === 'musicbot.update' ? 'update' : 'repair';
            if ($status === AgentJobStatus::Success) {
                $instance->setLastError(null);
                if (is_array($payload)) {
                    $this->mergeRuntimePayload($instance, $payload);
                }
                $this->musicbotRuntimeEventService->record($instance, $operation === 'repair' ? 'instance.repaired' : 'instance.updated', 'info', sprintf('Musicbot %s completed.', $operation), ['job_id' => $job->getId()]);
            } elseif ($status === AgentJobStatus::Failed) {
                $error = $this->extractError($job, $payload);
                $instance->setStatus(MusicbotInstanceStatus::Error);
                $instance->setLastError($error);
                $this->musicbotRuntimeEventService->record($instance, 'runtime.error', 'error', sprintf('Musicbot %s failed.', $operation), ['job_id' => $job->getId(), 'error' => $error]);
            }
        }

        if ($job->getType() === 'musicbot.service.action') {
            $action = strtolower((string) ($job->getPayload()['action'] ?? ''));
            if ($status === AgentJobStatus::Failed) {
                $error = $this->extractError($job, $payload);
                $instance->setStatus(MusicbotInstanceStatus::Error);
                $instance->setLastError($error);
                $this->musicbotRuntimeEventService->record($instance, 'runtime.error', 'error', sprintf('Musicbot action "%s" failed.', $action), ['job_id' => $job->getId(), 'action' => $action, 'error' => $error]);
                return;
            }
            if ($status === AgentJobStatus::Success) {
                $instance->setStatus($action === 'stop' ? MusicbotInstanceStatus::Stopped : MusicbotInstanceStatus::Running);
                $instance->setLastError(null);
                if (is_array($payload)) {
                    $this->mergeRuntimePayload($instance, $payload);
                }
                $eventType = match ($action) {
                    'start' => 'instance.started',
                    'stop' => 'instance.stopped',
                    'restart' => 'instance.restarted',
                    default => 'instance.'.($action ?: 'action'),
                };
                $this->musicbotRuntimeEventService->record($instance, $eventType, 'info', sprintf('Musicbot action "%s" completed.', $action), ['job_id' => $job->getId(), 'action' => $action]);
            }
        }

        if ($job->getType() === 'musicbot.status') {
            if ($status === AgentJobStatus::Failed) {
                $error = $this->extractError($job, $payload);
                $instance->setStatus(MusicbotInstanceStatus::Error);
                $instance->setLastError($error);
                $this->musicbotRuntimeEventService->record($instance, 'runtime.error', 'error', 'Musicbot status check failed.', ['job_id' => $job->getId(), 'error' => $error]);
                return;
            }
            if ($status === AgentJobStatus::Success && is_array($payload)) {
                $previousRuntimePayload = $instance->getRuntimePayload() ?? [];
                $previousPlaybackStatus = is_array($previousRuntimePayload['playback_status'] ?? null) ? $previousRuntimePayload['playback_status'] : [];
                $previousState = is_string($previousPlaybackStatus['playback_state'] ?? null) ? $previousPlaybackStatus['playback_state'] : '';

                $runtimeStatus = is_string($payload['status'] ?? null) ? MusicbotInstanceStatus::tryFrom($payload['status']) : null;
                if ($runtimeStatus instanceof MusicbotInstanceStatus) {
                    $instance->setStatus($runtimeStatus);
                } elseif (array_key_exists('running', $payload)) {
                    $instance->setStatus((bool) $payload['running'] ? MusicbotInstanceStatus::Running : MusicbotInstanceStatus::Stopped);
                }
                $runtimePayload = $this->mergeRuntimePayload($instance, $payload);
                $instance->setLastError($this->musicbotRuntimeStatusNormalizer->resolveActiveLastError($runtimePayload));

                $this->recordPlaybackStateTransition($instance, $previousState, $runtimePayload, $job->getId());

                if ($instance->getLastError() !== null) {
                    $this->musicbotRuntimeEventService->record($instance, 'runtime.error', 'error', 'Musicbot runtime reported an error.', ['job_id' => $job->getId(), 'error' => $instance->getLastError()]);
                }
            }
        }

        if ($job->getType() === 'musicbot.playback.action') {
            $action = strtolower((string) ($job->getPayload()['action'] ?? ''));
            if ($status === AgentJobStatus::Failed) {
                $error = $this->extractError($job, $payload);
                $instance->setLastError($error);
                $this->musicbotRuntimeEventService->record($instance, 'runtime.error', 'error', sprintf('Playback command "%s" failed.', $action), ['job_id' => $job->getId(), 'action' => $action, 'error' => $error]);
            } elseif ($status === AgentJobStatus::Success) {
                if (is_array($payload)) {
                    $this->mergeRuntimePayload($instance, $payload);
                }
                $this->musicbotRuntimeEventService->record($instance, 'playback.command', 'info', sprintf('Playback command "%s" accepted.', $action), ['job_id' => $job->getId(), 'action' => $action]);
            }
        }

        if ($job->getType() === 'musicbot.queue.sync' && $status === AgentJobStatus::Success && is_array($payload)) {
            $this->mergeRuntimePayload($instance, $payload);
            $this->musicbotRuntimeEventService->record($instance, 'queue.synced', 'info', 'Musicbot queue synchronized.', ['job_id' => $job->getId()]);
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function mergeRuntimePayload(MusicbotInstance $instance, array $payload): array
    {
        $existing = $instance->getRuntimePayload() ?? [];
        $incoming = is_array($payload['runtime'] ?? null) ? $payload['runtime'] : $payload;
        $payloadKind = $this->musicbotRuntimeStatusNormalizer->classifyPayload($payload);
        $this->debugMusicbotPayload('agent.payload.parsed', ['instance_id' => $instance->getId(), 'payload_kind' => $payloadKind] + MusicbotPayloadLogSummarizer::summarizeJobPayload($payload));
        $merged = $this->mergeArraysPreservingExisting($existing, $incoming);
        $normalized = $this->musicbotRuntimeStatusNormalizer->normalizePayload($merged);
        $stored = $this->musicbotSecretConfigService->sanitizePayload($normalized);
        $this->debugMusicbotPayload('database.payload.before_persist', ['instance_id' => $instance->getId()] + MusicbotPayloadLogSummarizer::summarizeRuntimePayload($stored));
        $instance->setRuntimePayload($stored);
        $this->debugMusicbotPayload('database.payload.after_persist', ['instance_id' => $instance->getId()] + MusicbotPayloadLogSummarizer::summarizeRuntimePayload($instance->getRuntimePayload() ?? []));

        return $normalized;
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $incoming @return array<string, mixed> */
    private function mergeArraysPreservingExisting(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value) && is_array($existing[$key] ?? null)) {
                $existing[$key] = $this->mergeArraysPreservingExisting($existing[$key], $value);
                continue;
            }
            // Explicit null from the runtime clears the field; empty string is treated as "no update".
            if (array_key_exists($key, $incoming) && $value === null) {
                $existing[$key] = null;
                continue;
            }
            if ($value !== null && $value !== '') {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }


    /** @param array<string, mixed>|null $payload */
    private function debugMusicbotStatus(string $stage, AgentJob $job, ?array $payload): void
    {
        $this->debugMusicbotPayload($stage, [
            'job_id' => $job->getId(),
            'job_type' => $job->getType(),
        ] + MusicbotPayloadLogSummarizer::summarizeJobPayload($payload));
    }

    /** @param array<string, mixed> $context */
    private function debugMusicbotPayload(string $stage, array $context): void
    {
        if ((string) ($_ENV['MUSICBOT_DEBUG_STATUS_FLOW'] ?? $_SERVER['MUSICBOT_DEBUG_STATUS_FLOW'] ?? getenv('MUSICBOT_DEBUG_STATUS_FLOW') ?: '') !== '1') {
            return;
        }

        error_log('[musicbot-status-flow] '.json_encode(['stage' => $stage] + $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function applyMusicbotTeamspeakBackendResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $nodeId = $job->getPayload()['node_id'] ?? null;
        if (!is_int($nodeId) && !is_string($nodeId)) {
            return;
        }

        $config = $this->musicbotTeamspeakBackendConfigRepository->findOneByNode($job->getNode());
        if (!$config instanceof MusicbotTeamspeakBackendConfig) {
            return;
        }

        $payload = is_array($payload) ? $payload : [];
        if ($status === AgentJobStatus::Success) {
            $config->applyAgentResult($payload + ['status' => 'ready']);
            if (($payload['status'] ?? '') === 'connected') {
                $config->applyAgentResult($payload + ['status' => 'connected']);
            }
            $config->setLastError(null);
            $eventType = match ($job->getType()) {
                'musicbot.teamspeak_backend.install',
                'musicbot.teamspeak_backend.install_official_client',
                'musicbot.teamspeak_backend.install_sdk_client' => 'teamspeak_backend.installed',
                default => 'teamspeak_backend.checked',
            };
            $this->recordNodeScopedMusicbotEvent((string) $nodeId, $eventType, 'info', 'TeamSpeak Client Backend validation completed.', ['job_id' => $job->getId(), 'status' => $config->getStatus()->value]);
            return;
        }

        $config->applyAgentResult($payload + ['status' => $payload['status'] ?? 'failed', 'last_error' => $this->extractError($job, $payload)]);
        $this->recordNodeScopedMusicbotEvent((string) $nodeId, 'teamspeak_backend.install_failed', 'error', 'TeamSpeak Client Backend validation failed.', ['job_id' => $job->getId(), 'status' => $config->getStatus()->value, 'error' => $config->getLastError()]);
    }

    /** @param array<string, mixed> $context */
    private function recordNodeScopedMusicbotEvent(string $nodeId, string $type, string $level, string $message, array $context = []): void
    {
        if (!$this->musicbotInstanceRepository instanceof MusicbotInstanceRepository) {
            return;
        }
        $instance = $this->musicbotInstanceRepository->findOneBy(['node' => $nodeId], ['updatedAt' => 'DESC']);
        if ($instance instanceof MusicbotInstance) {
            $this->musicbotRuntimeEventService->record($instance, $type, $level, $message, $context);
        }
    }

    private function recordPlaybackStateTransition(MusicbotInstance $instance, string $previousState, array $runtimePayload, ?string $jobId): void
    {
        $playbackStatus = is_array($runtimePayload['playback_status'] ?? null) ? $runtimePayload['playback_status'] : [];
        $newState = is_string($playbackStatus['playback_state'] ?? null) ? $playbackStatus['playback_state'] : '';

        if ($previousState === '' || $newState === '' || $previousState === $newState) {
            return;
        }

        $context = [
            'job_id' => $jobId,
            'previous_state' => $previousState,
            'new_state' => $newState,
            'track_id' => $playbackStatus['current_track_id'] ?? null,
            'track_title' => $playbackStatus['current_title'] ?? null,
        ];

        if ($newState === 'error') {
            $this->musicbotRuntimeEventService->record($instance, 'playback.error', 'error', 'Playback error occurred.', $context + ['error' => $playbackStatus['last_error'] ?? '']);
            return;
        }

        if (in_array($previousState, ['stopped', 'error'], true) && $newState === 'playing') {
            $this->musicbotRuntimeEventService->record($instance, 'playback.started', 'info', 'Playback started.', $context);
            return;
        }

        if ($previousState === 'playing' && $newState === 'paused') {
            $this->musicbotRuntimeEventService->record($instance, 'playback.paused', 'info', 'Playback paused.', $context);
            return;
        }

        if ($previousState === 'paused' && $newState === 'playing') {
            $this->musicbotRuntimeEventService->record($instance, 'playback.resumed', 'info', 'Playback resumed.', $context);
            return;
        }

        if (in_array($previousState, ['playing', 'paused'], true) && $newState === 'stopped') {
            $queueLength = (int) ($playbackStatus['queue_count'] ?? $playbackStatus['queue_length'] ?? 0);
            if ($queueLength === 0) {
                $this->musicbotRuntimeEventService->record($instance, 'queue.empty', 'info', 'Queue became empty after playback stopped.', $context);
            }
            $this->musicbotRuntimeEventService->record($instance, 'playback.stopped', 'info', 'Playback stopped.', $context);
        }
    }

    private function applyMusicbotConfigApplyResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $instance = $this->findMusicbotInstanceFromJob($job);
        if (!$instance instanceof MusicbotInstance) {
            return;
        }

        if ($status === AgentJobStatus::Success) {
            $this->musicbotRuntimeEventService->record($instance, 'config.applied', 'info', 'Runtime config applied successfully.', ['job_id' => $job->getId()]);
        } elseif ($status === AgentJobStatus::Failed) {
            $error = $this->extractError($job, $payload);
            $instance->setLastError($error);
            $this->musicbotRuntimeEventService->record($instance, 'config.apply_failed', 'error', 'Runtime config apply failed.', ['job_id' => $job->getId(), 'error' => $error]);
        }
    }

    private function applyMusicbotConnectionTestResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        $instance = $this->findMusicbotInstanceFromJob($job);
        if (!$instance instanceof MusicbotInstance) {
            return;
        }
        $platformValue = strtolower((string) ($job->getPayload()['platform'] ?? ''));
        $platform = MusicbotPlatform::tryFrom($platformValue);
        if (!$platform instanceof MusicbotPlatform) {
            return;
        }
        $connection = $this->musicbotConnectionRepository->findOneBy([
            'musicbotInstance' => $instance,
            'platform' => $platform,
        ]);
        if (!$connection instanceof MusicbotConnection) {
            return;
        }
        if ($status === AgentJobStatus::Success) {
            $runtimeStatus = is_string($payload['status'] ?? null) ? MusicbotConnectionStatus::tryFrom($payload['status']) : null;
            $connection->setStatus($runtimeStatus ?? MusicbotConnectionStatus::Connected);
            $connection->setLastConnectedAt(new \DateTimeImmutable());
            $connection->setLastError(null);
            $this->musicbotRuntimeEventService->record($instance, 'connector.status.changed', 'info', 'Musicbot connector test completed.', [
                'job_id' => $job->getId(),
                'platform' => $platform->value,
                'status' => $connection->getStatus()->value,
            ]);
        } elseif ($status === AgentJobStatus::Failed) {
            $error = $this->extractError($job, $payload);
            $connection->setStatus(MusicbotConnectionStatus::Error);
            $connection->setLastError($error);
            $this->musicbotRuntimeEventService->record($instance, 'connector.status.changed', 'error', 'Musicbot connector test failed.', [
                'job_id' => $job->getId(),
                'platform' => $platform->value,
                'status' => MusicbotConnectionStatus::Error->value,
                'error' => $error,
            ]);
        }
    }

    private function findMusicbotInstanceFromJob(AgentJob $job): ?MusicbotInstance
    {
        $instanceId = $job->getPayload()['instance_id'] ?? null;
        if (!is_int($instanceId) && !is_string($instanceId)) {
            return null;
        }
        return $this->musicbotInstanceRepository->findById((int) $instanceId);
    }

    private function extractError(AgentJob $job, ?array $payload): ?string
    {
        if (is_array($payload) && is_string($payload['last_error'] ?? null)) {
            return $payload['last_error'];
        }
        if (is_array($payload) && is_string($payload['error'] ?? null)) {
            return $payload['error'];
        }

        return $job->getErrorText();
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
            if (is_array($payload) && isset($payload['install_dir']) && is_string($payload['install_dir'])) {
                $node->setInstallPath($payload['install_dir']);
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
            if ($status === AgentJobStatus::Success && is_array($payload) && array_key_exists('running', $payload)) {
                $this->applyInstallStatusFromRuntime($node, (bool) $payload['running']);
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
            if (is_array($payload) && isset($payload['install_dir']) && is_string($payload['install_dir'])) {
                $node->setInstallPath($payload['install_dir']);
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
            if (is_array($payload)) {
                if (isset($payload['installed_version']) && is_string($payload['installed_version'])) {
                    $node->setInstalledVersion($payload['installed_version']);
                } elseif (isset($payload['version']) && is_string($payload['version'])) {
                    $node->setInstalledVersion($payload['version']);
                }
            }
            if (is_array($payload) && array_key_exists('running', $payload)) {
                $node->setRunning((bool) $payload['running']);
            }
            $this->applySinusbotDependencies($node, $payload);
        }

        if (in_array($job->getType(), ['sinusbot.status', 'sinusbot.service.action'], true)) {
            if (is_array($payload) && array_key_exists('running', $payload)) {
                $node->setRunning((bool) $payload['running']);
            }
            if (is_array($payload)) {
                if (array_key_exists('installed', $payload) && $payload['installed'] === true) {
                    $node->setInstallStatus('installed');
                }
                if (isset($payload['installed_version']) && is_string($payload['installed_version'])) {
                    $node->setInstalledVersion($payload['installed_version']);
                } elseif (isset($payload['version']) && is_string($payload['version'])) {
                    $node->setInstalledVersion($payload['version']);
                }
            }
            if ($status === AgentJobStatus::Success && is_array($payload) && array_key_exists('running', $payload)) {
                $this->applyInstallStatusFromRuntime($node, (bool) $payload['running']);
            }
            $this->applySinusbotDependencies($node, $payload);
        }

        if (is_array($payload) && isset($payload['last_error']) && is_string($payload['last_error'])) {
            $node->setLastError($payload['last_error']);
        }
    }

    private function applyInstallStatusFromRuntime(Ts3Node|Ts6Node|SinusbotNode $node, bool $running): void
    {
        if ($running && $node->getInstallStatus() !== 'installed') {
            $node->setInstallStatus('installed');
        }
    }

    private function applySinusbotDependencies(SinusbotNode $node, ?array $payload): void
    {
        if (!is_array($payload)) {
            return;
        }
        $dependencies = $payload['dependencies'] ?? null;
        if (!is_array($dependencies)) {
            $dependencies = $payload;
        }

        $node->setTs3ClientInstalled((bool) ($dependencies['ts3_client_installed'] ?? $node->isTs3ClientInstalled()));
        $node->setTs3ClientVersion(is_string($dependencies['ts3_client_version'] ?? null) ? $dependencies['ts3_client_version'] : null);
        $node->setTs3ClientPath(is_string($dependencies['ts3_client_path'] ?? null) ? $dependencies['ts3_client_path'] : null);
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
        if ($job->getType() === 'ts3.virtual.list') {
            $this->applyTs3VirtualServerList($job, $status, $payload);
            return;
        }

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
            $this->applyVirtualToken($server, Ts3Token::class, $payload['token'] ?? null, $payload['token_type'] ?? null);
        }

        if ($job->getType() === 'ts3.virtual.action') {
            $action = (string) ($job->getPayload()['action'] ?? '');
            if ($action === 'delete') {
                $server->archive();
                $server->setStatus('deleted');
            } else {
                $server->setStatus($action === 'stop' ? 'stopped' : 'running');
            }
        }

        if ($job->getType() === 'ts3.virtual.token.rotate' && is_array($payload)) {
            $this->applyVirtualToken($server, Ts3Token::class, $payload['token'] ?? null, $payload['token_type'] ?? null);
        }
    }

    private function applyTs3VirtualServerList(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        if ($status !== AgentJobStatus::Success || !is_array($payload)) {
            return;
        }

        $nodeId = $job->getPayload()['node_id'] ?? null;
        if (!is_int($nodeId) && !is_string($nodeId)) {
            return;
        }
        $node = $this->ts3NodeRepository->find((int) $nodeId);
        if (!$node instanceof Ts3Node) {
            return;
        }

        $servers = $payload['servers'] ?? null;
        if (!is_array($servers)) {
            return;
        }

        $seen = [];
        foreach ($servers as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $sid = isset($entry['sid']) && is_numeric($entry['sid']) ? (int) $entry['sid'] : 0;
            $name = is_string($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
            if ($sid <= 0 && $name === '') {
                continue;
            }

            $server = null;
            if ($sid > 0) {
                $server = $this->ts3VirtualServerRepository->findOneBy(['node' => $node, 'sid' => $sid, 'archivedAt' => null]);
            }
            if (!$server instanceof Ts3VirtualServer && $name !== '') {
                $server = $this->ts3VirtualServerRepository->findOneBy(['node' => $node, 'name' => $name, 'archivedAt' => null]);
                if ($server instanceof Ts3VirtualServer && $sid > 0 && $server->getSid() <= 0) {
                    $server->setSid($sid);
                }
            }
            if (!$server instanceof Ts3VirtualServer) {
                $server = new Ts3VirtualServer($node, 0, $sid, $name !== '' ? $name : sprintf('TS3 Server %d', $sid));
                $server->setStatus('external');
                $this->entityManager->persist($server);
            } elseif ($name !== '' && $server->getName() !== $name) {
                $server->setName($name);
            }

            if (isset($entry['voice_port']) && is_numeric($entry['voice_port'])) {
                $server->setVoicePort((int) $entry['voice_port']);
            }
            if (isset($entry['filetransfer_port']) && is_numeric($entry['filetransfer_port'])) {
                $server->setFiletransferPort((int) $entry['filetransfer_port']);
            }
            if (is_string($entry['status'] ?? null) && $entry['status'] !== '') {
                $server->setStatus((string) $entry['status']);
            }
            if ($sid > 0) {
                $seen[$sid] = true;
            }
        }

        $existingServers = $this->ts3VirtualServerRepository->findBy(['node' => $node, 'archivedAt' => null]);
        foreach ($existingServers as $existing) {
            $sid = $existing->getSid();
            if ($sid > 0 && !isset($seen[$sid])) {
                $existing->setStatus('missing');
            }
        }
    }

    private function applyTs6VirtualServerResult(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        if ($job->getType() === 'ts6.virtual.list') {
            $this->applyTs6VirtualServerList($job, $status, $payload);
            return;
        }

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

        if ($status !== AgentJobStatus::Success) {
            return;
        }

        if ($job->getType() === 'ts6.virtual.create') {
            if (is_array($payload)) {
                if (isset($payload['sid'])) {
                    $server->setSid((int) $payload['sid']);
                }
                if (isset($payload['voice_port'])) {
                    $server->setVoicePort((int) $payload['voice_port']);
                }
                if (isset($payload['filetransfer_port'])) {
                    $server->setFiletransferPort((int) $payload['filetransfer_port']);
                }
                $this->applyVirtualToken($server, Ts6Token::class, $payload['token'] ?? null, $payload['token_type'] ?? null);
            }
            $server->setStatus('running');
        }

        if ($job->getType() === 'ts6.virtual.action') {
            $action = (string) ($job->getPayload()['action'] ?? '');
            if ($action === 'delete') {
                $server->archive();
                $server->setStatus('deleted');
            } else {
                $server->setStatus($action === 'stop' ? 'stopped' : 'running');
            }
        }

        if ($job->getType() === 'ts6.virtual.token.rotate' && is_array($payload)) {
            $this->applyVirtualToken($server, Ts6Token::class, $payload['token'] ?? null, $payload['token_type'] ?? null);
        }
    }

    private function applyTs6VirtualServerList(AgentJob $job, AgentJobStatus $status, ?array $payload): void
    {
        if ($status !== AgentJobStatus::Success || !is_array($payload)) {
            return;
        }

        $nodeId = $job->getPayload()['node_id'] ?? null;
        if (!is_int($nodeId) && !is_string($nodeId)) {
            return;
        }
        $node = $this->ts6NodeRepository->find((int) $nodeId);
        if (!$node instanceof Ts6Node) {
            return;
        }

        $servers = $payload['servers'] ?? null;
        if (!is_array($servers)) {
            return;
        }

        $seen = [];
        foreach ($servers as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $sid = isset($entry['sid']) && is_numeric($entry['sid']) ? (int) $entry['sid'] : 0;
            $name = is_string($entry['name'] ?? null) ? trim((string) $entry['name']) : '';
            if ($sid <= 0 && $name === '') {
                continue;
            }

            $server = null;
            if ($sid > 0) {
                $server = $this->ts6VirtualServerRepository->findOneBy([
                    'node' => $node,
                    'sid' => $sid,
                    'archivedAt' => null,
                ]);
            }
            if (!$server instanceof Ts6VirtualServer && $name !== '') {
                $server = $this->ts6VirtualServerRepository->findOneBy([
                    'node' => $node,
                    'name' => $name,
                    'archivedAt' => null,
                ]);
                if ($server instanceof Ts6VirtualServer && $sid > 0 && $server->getSid() <= 0) {
                    $server->setSid($sid);
                }
            }

            if (!$server instanceof Ts6VirtualServer) {
                $server = new Ts6VirtualServer(
                    $node,
                    0,
                    $sid,
                    $name !== '' ? $name : sprintf('TS6 Server %d', $sid),
                    isset($entry['slots']) && is_numeric($entry['slots']) ? (int) $entry['slots'] : 0,
                );
                $server->setStatus('external');
                $this->entityManager->persist($server);
            } else {
                if ($name !== '' && $server->getName() !== $name) {
                    $server->setName($name);
                }
                if (isset($entry['slots']) && is_numeric($entry['slots'])) {
                    $server->setSlots((int) $entry['slots']);
                }
            }

            if (isset($entry['voice_port']) && is_numeric($entry['voice_port'])) {
                $server->setVoicePort((int) $entry['voice_port']);
            }
            if (isset($entry['filetransfer_port']) && is_numeric($entry['filetransfer_port'])) {
                $server->setFiletransferPort((int) $entry['filetransfer_port']);
            }
            if (is_string($entry['status'] ?? null) && $entry['status'] !== '') {
                $server->setStatus((string) $entry['status']);
            }

            if ($sid > 0) {
                $seen[$sid] = true;
            }
        }

        $existingServers = $this->ts6VirtualServerRepository->findBy([
            'node' => $node,
            'archivedAt' => null,
        ]);

        foreach ($existingServers as $existing) {
            $sid = $existing->getSid();
            if ($sid > 0 && !isset($seen[$sid])) {
                $existing->setStatus('missing');
            }
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
    private function applyVirtualToken(Ts3VirtualServer|Ts6VirtualServer $server, string $tokenClass, mixed $tokenValue, mixed $tokenType = null): void
    {
        if (!is_string($tokenValue) || $tokenValue === '') {
            return;
        }

        $type = is_string($tokenType) && $tokenType !== '' ? $tokenType : 'owner';
        $existing = $this->entityManager->getRepository($tokenClass)->findOneBy([
            'virtualServer' => $server,
            'active' => true,
        ]);
        if ($existing instanceof Ts3Token || $existing instanceof Ts6Token) {
            $existing->deactivate();
        }

        if ($tokenClass === Ts3Token::class) {
            $token = new Ts3Token($server, $this->crypto->encrypt($tokenValue), $type);
        } else {
            $token = new Ts6Token($server, $this->crypto->encrypt($tokenValue), $type);
        }
        $this->entityManager->persist($token);
    }
}
