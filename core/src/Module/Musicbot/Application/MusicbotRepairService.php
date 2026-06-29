<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcherInterface;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

/**
 * Dispatches repair jobs to the agent for a given MusicbotInstance.
 *
 * Customer-visible repair actions are a restricted subset of admin actions.
 */
final class MusicbotRepairService
{
    /** Repair actions available to customers (non-destructive). */
    private const CUSTOMER_ALLOWED_ACTIONS = [
        'service_restart',
        'force_status_refresh',
        'force_queue_sync',
        'reconnect_connector',
        'clear_cache',
    ];

    /** All repair actions available to admins. */
    private const ALL_ACTIONS = [
        'service_restart',
        'daemon_reload',
        'remove_stale_socket',
        'repair_dir_permissions',
        'create_missing_dirs',
        'reinit_pulseaudio',
        'restart_teamspeak_bridge',
        'rewrite_config',
        'resend_config_apply',
        'force_status_refresh',
        'force_queue_sync',
        'reinstall_binary',
        'rewrite_systemd_unit',
        'sync_plugin_status',
        'sync_playlist_status',
        'sync_autodj_status',
        'reconnect_connector',
        'ffmpeg_dependency_check',
        'ytdlp_dependency_check',
        'rewrite_queue',
        'repair_playlists',
        'repair_plugin_registry',
        'repair_autodj',
        'repair_youtube',
        'repair_upload_dirs',
        'clear_cache',
        'restart_runtime',
    ];

    public function __construct(
        private readonly AgentJobDispatcherInterface $jobDispatcher,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Execute a repair action for the given instance, enforcing caller permissions.
     *
     * @return array<string, mixed>
     */
    public function repair(MusicbotInstance $instance, User $actor, string $action): array
    {
        $isAdmin = $actor->isAdmin();

        if (!in_array($action, self::ALL_ACTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown repair action: %s', $action));
        }
        if (!$isAdmin && !in_array($action, self::CUSTOMER_ALLOWED_ACTIONS, true)) {
            throw new \RuntimeException(sprintf('Repair action "%s" requires admin privileges.', $action));
        }

        $payload = $this->buildRepairPayload($instance, $action);
        $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.health.repair', $payload);

        $this->auditLogger->log($actor, 'musicbot.repair', [
            'instance_id' => $instance->getId(),
            'action' => $action,
            'job_id' => $job->getId(),
        ]);

        return [
            'action' => $action,
            'job_id' => $job->getId(),
            'instance_id' => $instance->getId(),
            'dispatched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return string[] */
    public function allowedActionsForActor(User $actor): array
    {
        return $actor->isAdmin() ? self::ALL_ACTIONS : self::CUSTOMER_ALLOWED_ACTIONS;
    }

    /** @return array<string, mixed> */
    private function buildRepairPayload(MusicbotInstance $instance, string $action): array
    {
        return [
            'instance_id' => (string) $instance->getId(),
            'service_name' => $instance->getServiceName(),
            'install_dir' => $instance->getInstallPath(),
            'install_path' => $instance->getInstallPath(),
            'repair_action' => $action,
        ];
    }
}
