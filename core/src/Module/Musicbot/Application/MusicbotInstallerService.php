<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

/**
 * Orchestrates install, reinstall and rebuild operations for a MusicbotInstance.
 *
 * The service dispatches agent jobs for the actual low-level work and optionally
 * triggers a follow-up health check after each operation.
 */
final class MusicbotInstallerService
{
    public function __construct(
        private readonly AgentJobDispatcherInterface $jobDispatcher,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Dispatch a fresh install job for the given instance.
     *
     * @param array<string, mixed> $options  Optional overrides (runtime_binary, enable, etc.)
     * @return array<string, mixed>
     */
    public function install(MusicbotInstance $instance, User $actor, array $options = []): array
    {
        $payload = $this->buildBasePayload($instance);
        $payload['enable'] = $options['enable'] ?? true;
        if (isset($options['runtime_binary'])) {
            $payload['runtime_binary'] = $options['runtime_binary'];
        }

        $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.install', $payload);

        $this->auditLogger->log($actor, 'musicbot.installer.install', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
        ]);

        return $this->buildResult('install', $instance, $job);
    }

    /**
     * Dispatch an update job for the given instance.
     *
     * The agent creates a timestamped backup of the runtime binary before replacing
     * it so a rollback is always possible without additional infrastructure.
     *
     * @param array<string, mixed> $options  Optional overrides (runtime_binary, restart, etc.)
     * @return array<string, mixed>
     */
    public function update(MusicbotInstance $instance, User $actor, array $options = []): array
    {
        $payload = $this->buildBasePayload($instance);
        $payload['restart'] = $options['restart'] ?? true;
        if (isset($options['runtime_binary'])) {
            $payload['runtime_binary'] = $options['runtime_binary'];
        }

        $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.update', $payload);

        $healthJob = $this->dispatchHealthCheck($instance);

        $this->auditLogger->log($actor, 'musicbot.installer.update', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'health_job_id' => $healthJob->getId(),
        ]);

        return $this->buildResult('update', $instance, $job, $healthJob);
    }

    /**
     * Reinstall – identical to install but always overwrites an existing installation.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function reinstall(MusicbotInstance $instance, User $actor, array $options = []): array
    {
        $payload = $this->buildBasePayload($instance);
        $payload['enable'] = $options['enable'] ?? true;
        $payload['reinstall'] = true;
        if (isset($options['runtime_binary'])) {
            $payload['runtime_binary'] = $options['runtime_binary'];
        }

        $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.install', $payload);

        $this->auditLogger->log($actor, 'musicbot.installer.reinstall', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
        ]);

        return $this->buildResult('reinstall', $instance, $job);
    }

    /**
     * Full rebuild: repair + ensure directories + rewrite config + restart.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function rebuild(MusicbotInstance $instance, User $actor, array $options = []): array
    {
        $payload = $this->buildBasePayload($instance);
        if (isset($options['runtime_binary'])) {
            $payload['runtime_binary'] = $options['runtime_binary'];
        }

        $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.repair', $payload);

        $healthJob = $this->dispatchHealthCheck($instance);

        $this->auditLogger->log($actor, 'musicbot.installer.rebuild', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'health_job_id' => $healthJob->getId(),
        ]);

        return $this->buildResult('rebuild', $instance, $job, $healthJob);
    }

    /**
     * Validate – trigger a full health check and return the job reference.
     *
     * @return array<string, mixed>
     */
    public function validate(MusicbotInstance $instance, User $actor): array
    {
        $job = $this->dispatchHealthCheck($instance);

        $this->auditLogger->log($actor, 'musicbot.installer.validate', [
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
        ]);

        return $this->buildResult('validate', $instance, $job);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function dispatchHealthCheck(MusicbotInstance $instance): AgentJob
    {
        return $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.health.check', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) ($instance->getCustomer()->getId() ?? 0),
            'node_id' => $instance->getNode()->getId(),
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
        ]);
    }

    /** @return array<string, mixed> */
    private function buildBasePayload(MusicbotInstance $instance): array
    {
        return [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) ($instance->getCustomer()->getId() ?? 0),
            'node_id' => $instance->getNode()->getId(),
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
        ];
    }

    /** @return array<string, mixed> */
    private function buildResult(string $operation, MusicbotInstance $instance, AgentJob $job, ?AgentJob $healthJob = null): array
    {
        $result = [
            'operation' => $operation,
            'instance_id' => $instance->getId(),
            'job_id' => $job->getId(),
            'dispatched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        if ($healthJob !== null) {
            $result['health_check_job_id'] = $healthJob->getId();
        }
        return $result;
    }
}
