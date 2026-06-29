<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotHealthStatus;
use App\Module\Musicbot\Domain\Enum\MusicbotInstanceStatus;

/**
 * Builds a structured health report for a MusicbotInstance based on persisted state.
 *
 * All checks run locally against the database / runtimePayload — no network calls.
 * For deep node-level checks the caller must first trigger a musicbot.health.check
 * agent job and persist its result into runtimePayload before calling this service.
 */
final class MusicbotHealthService
{
    /** Maximum age (seconds) for runtimePayload data before it is considered stale. */
    private const RUNTIME_STALE_SECONDS = 300;

    /** Maximum age (seconds) for the agent job timestamp before it is considered stale. */
    private const AGENT_JOB_STALE_SECONDS = 600;

    /**
     * Run all health checks for the given instance.
     *
     * @return array{overall: MusicbotHealthStatus, checks: array<string, array<string, mixed>>, checked_at: string}
     */
    public function check(MusicbotInstance $instance, bool $adminView = false): array
    {
        $runtime = $instance->getRuntimePayload() ?? [];
        $config = $instance->getInstanceConfig();
        $checks = [];

        $checks['service_running'] = $this->checkServiceRunning($instance, $runtime);
        $checks['binary_present'] = $this->checkBinaryPresent($runtime);
        $checks['config_present'] = $this->checkConfigPresent($runtime);
        $checks['control_socket'] = $this->checkControlSocket($runtime);
        $checks['runtime_ready'] = $this->checkRuntimeReady($runtime);
        $checks['connector_status'] = $this->checkConnectorStatus($runtime);
        $checks['teamspeak_connected'] = $this->checkTeamspeakConnected($runtime);
        $checks['audio_backend'] = $this->checkAudioBackend($runtime);
        $checks['pulseaudio_socket'] = $this->checkPulseaudioSocket($runtime);
        $checks['pulseaudio_sink_source'] = $this->checkPulseaudioSinkSource($runtime);
        $checks['xvfb_running'] = $this->checkXvfbRunning($runtime);
        $checks['teamspeak_client'] = $this->checkTeamspeakClient($runtime);
        $checks['ffmpeg_present'] = $this->checkFfmpegPresent($runtime);
        $checks['ytdlp_present'] = $this->checkYtdlpPresent($runtime);
        $checks['upload_dir'] = $this->checkUploadDir($runtime);
        $checks['tracks_dir'] = $this->checkTracksDir($runtime);
        $checks['queue_sync'] = $this->checkQueueSync($runtime, $config);
        $checks['last_agent_job'] = $this->checkLastAgentJob($runtime);
        $checks['runtime_status_fresh'] = $this->checkRuntimeStatusFresh($instance);

        if (!$adminView) {
            $checks = $this->stripSensitiveDetails($checks);
        }

        $statuses = array_map(
            static fn (array $c): MusicbotHealthStatus => MusicbotHealthStatus::from($c['status']),
            $checks,
        );
        $overall = MusicbotHealthStatus::aggregate(...$statuses);

        return [
            'overall' => $overall,
            'checks' => $checks,
            'checked_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function checkServiceRunning(MusicbotInstance $instance, array $runtime): array
    {
        $running = (bool) ($runtime['running'] ?? false);
        $instanceStatus = $instance->getStatus();

        if ($instanceStatus === MusicbotInstanceStatus::Running && $running) {
            return $this->ok('service_running', 'Systemd service is active.');
        }
        if ($instanceStatus === MusicbotInstanceStatus::Stopped) {
            return $this->warn('service_running', 'Service is intentionally stopped.', [], 'Start the service to resume operation.', false);
        }
        if ($instanceStatus === MusicbotInstanceStatus::Provisioning) {
            return $this->unknown('service_running', 'Instance is still being provisioned.');
        }
        if (!$running && $runtime !== []) {
            return $this->failed('service_running', 'Systemd service is not running.', ['instance_status' => $instanceStatus->value], 'Restart the service.', true, 'service_restart');
        }

        return $this->unknown('service_running', 'Service status not yet available.');
    }

    /** @return array<string, mixed> */
    private function checkBinaryPresent(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['binary_present'])) {
            return $this->unknown('binary_present', 'Binary check not yet performed.');
        }
        if ($health['binary_present']) {
            return $this->ok('binary_present', 'easywi-musicbot binary found.');
        }

        return $this->failed('binary_present', 'easywi-musicbot binary is missing.', [], 'Reinstall or repair the instance.', true, 'reinstall_binary');
    }

    /** @return array<string, mixed> */
    private function checkConfigPresent(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['config_present'])) {
            return $this->unknown('config_present', 'Config check not yet performed.');
        }
        if ($health['config_present']) {
            return $this->ok('config_present', 'config.json is present and readable.');
        }

        return $this->failed('config_present', 'config.json is missing or unreadable.', [], 'Re-apply runtime config.', true, 'rewrite_config');
    }

    /** @return array<string, mixed> */
    private function checkControlSocket(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['control_socket_present'])) {
            return $this->unknown('control_socket', 'Control socket check not yet performed.');
        }
        if ($health['control_socket_present'] && ($health['control_socket_responsive'] ?? false)) {
            return $this->ok('control_socket', 'Control socket is present and responding.');
        }
        if ($health['control_socket_present'] && !($health['control_socket_responsive'] ?? false)) {
            return $this->degraded('control_socket', 'Control socket present but not responding.', [], 'Remove stale socket and restart service.', true, 'remove_stale_socket');
        }

        return $this->degraded('control_socket', 'Control socket not found.', [], 'Restart the service to recreate the socket.', true, 'service_restart');
    }

    /** @return array<string, mixed> */
    private function checkRuntimeReady(array $runtime): array
    {
        $ready = $runtime['runtime_ready'] ?? null;
        if ($ready === null) {
            return $this->unknown('runtime_ready', 'Runtime readiness not yet reported.');
        }

        return $ready
            ? $this->ok('runtime_ready', 'Runtime reports ready.')
            : $this->degraded('runtime_ready', 'Runtime is not ready.', ['status' => $runtime['status'] ?? 'unknown'], 'Check service logs for startup errors.', false);
    }

    /** @return array<string, mixed> */
    private function checkConnectorStatus(array $runtime): array
    {
        $connectors = $runtime['connectors'] ?? null;
        if ($connectors === null) {
            return $this->unknown('connector_status', 'Connector status not yet reported.');
        }
        $failed = [];
        foreach ((array) $connectors as $name => $state) {
            $connStatus = is_array($state) ? ($state['status'] ?? $state) : $state;
            if ($connStatus !== 'connected' && $connStatus !== 'ok' && $connStatus !== 'active') {
                $failed[] = $name;
            }
        }
        if ($failed === []) {
            return $this->ok('connector_status', 'All connectors active.');
        }

        return $this->degraded('connector_status', sprintf('Connector(s) not active: %s.', implode(', ', $failed)), ['failed' => $failed], 'Reconnect the affected connectors.', true, 'reconnect_connector');
    }

    /** @return array<string, mixed> */
    private function checkTeamspeakConnected(array $runtime): array
    {
        $ts = $runtime['teamspeak'] ?? null;
        if ($ts === null) {
            return $this->unknown('teamspeak_connected', 'TeamSpeak status not yet reported.');
        }
        $connected = is_array($ts) ? ($ts['connected'] ?? false) : (bool) $ts;
        if ($connected) {
            return $this->ok('teamspeak_connected', 'TeamSpeak is connected.');
        }

        return $this->degraded('teamspeak_connected', 'TeamSpeak is not connected.', [], 'Check TS server address and credentials.', true, 'restart_teamspeak_bridge');
    }

    /** @return array<string, mixed> */
    private function checkAudioBackend(array $runtime): array
    {
        $ready = $runtime['audio_backend_ready'] ?? null;
        if ($ready === null) {
            return $this->unknown('audio_backend', 'Audio backend status not yet reported.');
        }

        return $ready
            ? $this->ok('audio_backend', 'Audio backend is ready.')
            : $this->degraded('audio_backend', 'Audio backend is not ready.', [], 'Reinitialize PulseAudio.', true, 'reinit_pulseaudio');
    }

    /** @return array<string, mixed> */
    private function checkPulseaudioSocket(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['pulseaudio_socket_present'])) {
            return $this->unknown('pulseaudio_socket', 'PulseAudio socket check not performed.');
        }
        if ($health['pulseaudio_socket_present']) {
            return $this->ok('pulseaudio_socket', 'PulseAudio socket found.');
        }

        return $this->degraded('pulseaudio_socket', 'PulseAudio socket not found.', [], 'Reinitialize PulseAudio.', true, 'reinit_pulseaudio');
    }

    /** @return array<string, mixed> */
    private function checkPulseaudioSinkSource(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['pulseaudio_sink_ok'])) {
            return $this->unknown('pulseaudio_sink_source', 'PulseAudio sink/source check not performed.');
        }
        if ($health['pulseaudio_sink_ok'] && ($health['pulseaudio_source_ok'] ?? false)) {
            return $this->ok('pulseaudio_sink_source', 'PulseAudio sink and source are present.');
        }

        return $this->degraded('pulseaudio_sink_source', 'PulseAudio sink or source missing.', [], 'Reinitialize PulseAudio.', true, 'reinit_pulseaudio');
    }

    /** @return array<string, mixed> */
    private function checkXvfbRunning(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['xvfb_running'])) {
            return $this->unknown('xvfb_running', 'Xvfb status not yet checked.');
        }
        if ($health['xvfb_running']) {
            return $this->ok('xvfb_running', 'Xvfb virtual display is running.');
        }

        return $this->degraded('xvfb_running', 'Xvfb is not running.', [], 'Restart the service to start Xvfb.', true, 'service_restart');
    }

    /** @return array<string, mixed> */
    private function checkTeamspeakClient(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['teamspeak_client_running'])) {
            return $this->unknown('teamspeak_client', 'TeamSpeak client status not yet checked.');
        }
        if ($health['teamspeak_client_running']) {
            return $this->ok('teamspeak_client', 'TeamSpeak client process is running.');
        }

        return $this->degraded('teamspeak_client', 'TeamSpeak client is not running.', [], 'Restart TeamSpeak bridge.', true, 'restart_teamspeak_bridge');
    }

    /** @return array<string, mixed> */
    private function checkFfmpegPresent(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['ffmpeg_present'])) {
            return $this->unknown('ffmpeg_present', 'ffmpeg check not yet performed.');
        }
        if ($health['ffmpeg_present']) {
            return $this->ok('ffmpeg_present', 'ffmpeg is available.');
        }

        return $this->failed('ffmpeg_present', 'ffmpeg is missing — audio playback will not work.', [], 'Install ffmpeg on the node.', false);
    }

    /** @return array<string, mixed> */
    private function checkYtdlpPresent(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['ytdlp_present'])) {
            return $this->unknown('ytdlp_present', 'yt-dlp check not yet performed.');
        }
        if ($health['ytdlp_present']) {
            return $this->ok('ytdlp_present', 'yt-dlp is available.');
        }

        return $this->warn('ytdlp_present', 'yt-dlp is missing — YouTube downloads will not work.', [], 'Install yt-dlp on the node.', false);
    }

    /** @return array<string, mixed> */
    private function checkUploadDir(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['upload_dir_writable'])) {
            return $this->unknown('upload_dir', 'Upload directory check not yet performed.');
        }
        if ($health['upload_dir_writable']) {
            return $this->ok('upload_dir', 'Upload directory is present and writable.');
        }

        return $this->failed('upload_dir', 'Upload directory is missing or not writable.', [], 'Repair directory permissions.', true, 'repair_dir_permissions');
    }

    /** @return array<string, mixed> */
    private function checkTracksDir(array $runtime): array
    {
        $health = $runtime['health'] ?? [];
        if (!isset($health['tracks_dir_readable'])) {
            return $this->unknown('tracks_dir', 'Tracks directory check not yet performed.');
        }
        if ($health['tracks_dir_readable']) {
            return $this->ok('tracks_dir', 'Track directory is readable by the runtime.');
        }

        return $this->degraded('tracks_dir', 'Track directory is not readable by the runtime.', [], 'Repair directory permissions.', true, 'repair_dir_permissions');
    }

    /** @param array<string, mixed> $config */
    private function checkQueueSync(array $runtime, array $config): array
    {
        $lastSync = $runtime['queue_sync_at'] ?? $config['queue_sync_at'] ?? null;
        if ($lastSync === null) {
            return $this->unknown('queue_sync', 'Queue sync timestamp not available.');
        }
        try {
            $syncTime = new \DateTimeImmutable((string) $lastSync);
            $age = (new \DateTimeImmutable())->getTimestamp() - $syncTime->getTimestamp();
        } catch (\Throwable) {
            return $this->unknown('queue_sync', 'Queue sync timestamp could not be parsed.');
        }
        if ($age <= 120) {
            return $this->ok('queue_sync', 'Queue sync is current.', ['last_sync_seconds_ago' => $age]);
        }
        if ($age <= 600) {
            return $this->warn('queue_sync', sprintf('Queue sync is %d seconds old.', $age), ['last_sync_seconds_ago' => $age], 'Force a queue sync.', true, 'force_queue_sync');
        }

        return $this->degraded('queue_sync', sprintf('Queue sync is stale (%d seconds old).', $age), ['last_sync_seconds_ago' => $age], 'Force a queue sync.', true, 'force_queue_sync');
    }

    /** @return array<string, mixed> */
    private function checkLastAgentJob(array $runtime): array
    {
        $lastJob = $runtime['last_agent_job_at'] ?? null;
        if ($lastJob === null) {
            return $this->unknown('last_agent_job', 'No agent job timestamp available.');
        }
        try {
            $jobTime = new \DateTimeImmutable((string) $lastJob);
            $age = (new \DateTimeImmutable())->getTimestamp() - $jobTime->getTimestamp();
        } catch (\Throwable) {
            return $this->unknown('last_agent_job', 'Agent job timestamp could not be parsed.');
        }
        if ($age <= self::AGENT_JOB_STALE_SECONDS) {
            return $this->ok('last_agent_job', sprintf('Last agent job was %d seconds ago.', $age), ['age_seconds' => $age]);
        }

        return $this->warn('last_agent_job', sprintf('Last agent job was %d seconds ago.', $age), ['age_seconds' => $age], 'Trigger a status refresh.', true, 'force_status_refresh');
    }

    /** @return array<string, mixed> */
    private function checkRuntimeStatusFresh(MusicbotInstance $instance): array
    {
        $updatedAt = $instance->getUpdatedAt();
        $age = (new \DateTimeImmutable())->getTimestamp() - $updatedAt->getTimestamp();

        if ($instance->getRuntimePayload() === null) {
            return $this->unknown('runtime_status_fresh', 'No runtime status available yet.');
        }
        if ($age <= self::RUNTIME_STALE_SECONDS) {
            return $this->ok('runtime_status_fresh', sprintf('Runtime status was updated %d seconds ago.', $age), ['age_seconds' => $age]);
        }

        return $this->warn('runtime_status_fresh', sprintf('Runtime status is %d seconds old.', $age), ['age_seconds' => $age], 'Trigger a status refresh.', true, 'force_status_refresh');
    }

    /**
     * Remove details that might expose file paths or internal info for non-admin users.
     *
     * @param array<string, array<string, mixed>> $checks
     * @return array<string, array<string, mixed>>
     */
    private function stripSensitiveDetails(array $checks): array
    {
        $sensitiveDetailKeys = ['path', 'socket', 'dir', 'binary', 'unit'];
        foreach ($checks as $key => $check) {
            $details = $check['details'] ?? [];
            foreach ($sensitiveDetailKeys as $sensitiveKey) {
                foreach (array_keys($details) as $detailKey) {
                    if (str_contains((string) $detailKey, $sensitiveKey)) {
                        unset($checks[$key]['details'][$detailKey]);
                    }
                }
            }
        }

        return $checks;
    }

    /** @param array<string, mixed> $details */
    private function ok(string $name, string $message, array $details = []): array
    {
        return $this->build($name, MusicbotHealthStatus::Healthy, $message, $details, null, false, null);
    }

    /** @param array<string, mixed> $details */
    private function warn(string $name, string $message, array $details = [], ?string $action = null, bool $autoRepair = false, ?string $repairAction = null): array
    {
        return $this->build($name, MusicbotHealthStatus::Warning, $message, $details, $action, $autoRepair, $repairAction);
    }

    /** @param array<string, mixed> $details */
    private function degraded(string $name, string $message, array $details = [], ?string $action = null, bool $autoRepair = false, ?string $repairAction = null): array
    {
        return $this->build($name, MusicbotHealthStatus::Degraded, $message, $details, $action, $autoRepair, $repairAction);
    }

    /** @param array<string, mixed> $details */
    private function failed(string $name, string $message, array $details = [], ?string $action = null, bool $autoRepair = false, ?string $repairAction = null): array
    {
        return $this->build($name, MusicbotHealthStatus::Failed, $message, $details, $action, $autoRepair, $repairAction);
    }

    /** @param array<string, mixed> $details */
    private function unknown(string $name, string $message, array $details = []): array
    {
        return $this->build($name, MusicbotHealthStatus::Unknown, $message, $details, null, false, null);
    }

    /** @param array<string, mixed> $details */
    private function build(string $name, MusicbotHealthStatus $status, string $message, array $details, ?string $recommendedAction, bool $autoRepairAvailable, ?string $repairAction): array
    {
        return [
            'name' => $name,
            'status' => $status->value,
            'message' => $message,
            'details' => $details,
            'recommended_action' => $recommendedAction,
            'auto_repair_available' => $autoRepairAvailable,
            'repair_action' => $repairAction,
        ];
    }
}
