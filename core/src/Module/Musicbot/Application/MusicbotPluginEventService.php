<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotQueueItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotPluginEventService
{
    public function __construct(
        private readonly PluginRegistryService $registry,
        private readonly MusicbotPluginRepository $pluginRepository,
        private readonly MusicbotPluginActionService $actionService,
        private readonly MusicbotPluginLogService $logService,
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function dispatch(MusicbotInstance $instance, string $event, array $payload = []): array
    {
        if (!in_array($event, $this->registry->supportedEvents(), true)) {
            throw new \InvalidArgumentException('Unsupported plugin event.');
        }
        $basePayload = array_merge($payload, [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'service_name' => $instance->getServiceName(),
            'platform' => (string) ($payload['platform'] ?? 'teamspeak'),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
        $results = [];
        foreach ($this->pluginRepository->findBy(['instance' => $instance, 'enabled' => true]) as $plugin) {
            if (!$plugin instanceof MusicbotPlugin) { continue; }
            $manifest = $this->registry->findManifest($plugin->getIdentifier());
            if ($manifest === null || !in_array($event, $manifest->events, true)) { continue; }
            try {
                $results[$plugin->getIdentifier()] = $this->handlePlugin($plugin, $event, $basePayload);
            } catch (\Throwable $e) {
                $this->logService->log($instance, $plugin->getIdentifier(), $event, null, 'error', $e->getMessage(), ['payload' => $basePayload]);
                $config = $plugin->getConfig();
                $config['_last_error'] = $e->getMessage();
                $config['_last_trigger'] = $event;
                $plugin->setConfig($config);
                $this->entityManager->flush();
                $results[$plugin->getIdentifier()] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }
        return $results;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function handlePlugin(MusicbotPlugin $plugin, string $event, array $payload): array
    {
        return match ($plugin->getIdentifier()) {
            'easywi.welcome_song' => $this->handleWelcomeSong($plugin, $event, $payload),
            'easywi.idle_playlist' => $this->handleIdlePlaylist($plugin, $event, $payload),
            default => ['status' => 'ignored'],
        };
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function handleWelcomeSong(MusicbotPlugin $plugin, string $event, array $payload): array
    {
        if ($event !== 'user_joined_channel') { return ['status' => 'ignored']; }
        $instance = $plugin->getInstance();
        if (!$instance instanceof MusicbotInstance) { return ['status' => 'ignored']; }
        $config = $plugin->getConfig();
        if (($config['enabled'] ?? true) === false) { return ['status' => 'disabled']; }
        if (!$this->isAllowedChannel($config, (string) ($payload['channel_id'] ?? ''))) { return ['status' => 'channel_ignored']; }
        if ($this->hasIgnoredGroup($config, (array) ($payload['server_groups'] ?? []))) { return ['status' => 'group_ignored']; }
        if (($config['only_when_idle'] ?? true) && !$this->isIdle($instance)) { $this->logService->log($instance, $plugin->getIdentifier(), $event, null, 'skipped', 'Playback is active.'); return ['status' => 'skipped_active']; }
        if (!$this->cooldownAllowed($plugin, (int) ($config['cooldown_seconds'] ?? 60))) { return ['status' => 'cooldown']; }
        $userKey = (string) ($payload['user_id'] ?? $payload['client_id'] ?? 'anonymous');
        if (!$this->dailyLimitAllowed($plugin, $userKey, (int) ($config['max_plays_per_user_per_day'] ?? 3))) { return ['status' => 'daily_limit']; }

        $action = $this->welcomeAction($config);
        $result = $this->actionService->execute($instance->getCustomer(), $instance, $action, $config);
        if (($config['volume_override'] ?? null) !== null) { $this->actionService->execute($instance->getCustomer(), $instance, 'set_volume', ['volume' => (int) $config['volume_override']]); }
        if (($config['send_chat_message'] ?? false) && trim((string) ($config['chat_message_text'] ?? '')) !== '') { $this->actionService->execute($instance->getCustomer(), $instance, 'send_chat_message', ['message' => (string) $config['chat_message_text']]); }
        $this->markTriggered($plugin, $event, $userKey);
        $this->logService->log($instance, $plugin->getIdentifier(), $event, $action, 'success', 'Welcome Song triggered.', ['result' => $result]);
        return ['status' => 'success', 'action' => $action, 'result' => $result];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function handleIdlePlaylist(MusicbotPlugin $plugin, string $event, array $payload): array
    {
        if (!in_array($event, ['queue_empty', 'playback_stopped', 'scheduled_tick'], true)) { return ['status' => 'ignored']; }
        $instance = $plugin->getInstance();
        if (!$instance instanceof MusicbotInstance) { return ['status' => 'ignored']; }
        $config = $plugin->getConfig();
        if (($config['enabled'] ?? true) === false) { return ['status' => 'disabled']; }
        if (!$this->isIdle($instance) || count($this->queueItemRepository->findQueueForInstanceOrdered($instance)) > 0) { return ['status' => 'not_idle']; }
        if (!$this->cooldownAllowed($plugin, (int) ($config['idle_seconds'] ?? 60))) { return ['status' => 'waiting']; }
        if (!$this->insideConfiguredWindow((string) ($config['allowed_time_window'] ?? ''))) { return ['status' => 'outside_window']; }
        if (($config['volume'] ?? null) !== null) { $this->actionService->execute($instance->getCustomer(), $instance, 'set_volume', ['volume' => (int) $config['volume']]); }
        $action = trim((string) ($config['radio_url'] ?? '')) !== '' ? 'play_radio' : 'play_playlist';
        $result = $this->actionService->execute($instance->getCustomer(), $instance, $action, ['playlist_id' => (int) ($config['playlist_id'] ?? 0), 'radio_url' => (string) ($config['radio_url'] ?? ''), 'shuffle' => (bool) ($config['shuffle'] ?? true)]);
        $this->markTriggered($plugin, $event, 'idle');
        $this->logService->log($instance, $plugin->getIdentifier(), $event, $action, 'success', 'Idle Playlist triggered.', ['result' => $result]);
        return ['status' => 'success', 'action' => $action, 'result' => $result];
    }

    /** @param array<string, mixed> $config */
    private function isAllowedChannel(array $config, string $channelId): bool
    {
        $allowed = array_filter(array_map('strval', (array) ($config['allowed_channels'] ?? [])));
        return $allowed === [] || in_array($channelId, $allowed, true);
    }

    /** @param array<string, mixed> $config @param array<int, mixed> $groups */
    private function hasIgnoredGroup(array $config, array $groups): bool
    {
        $ignored = array_map('strval', (array) ($config['ignored_server_groups'] ?? []));
        return array_intersect($ignored, array_map('strval', $groups)) !== [];
    }

    private function isIdle(MusicbotInstance $instance): bool
    {
        $payload = $instance->getRuntimePayload() ?? [];
        $state = strtolower((string) ($payload['playback_status']['playback_state'] ?? $payload['playback']['state'] ?? $instance->getStatus()->value));
        return in_array($state, ['idle', 'stopped', 'stop', 'offline', 'unknown'], true);
    }

    private function cooldownAllowed(MusicbotPlugin $plugin, int $seconds): bool
    {
        $last = (string) ($plugin->getConfig()['_last_triggered_at'] ?? '');
        return $last === '' || (time() - strtotime($last)) >= max(0, $seconds);
    }

    private function dailyLimitAllowed(MusicbotPlugin $plugin, string $userKey, int $limit): bool
    {
        if ($limit <= 0) { return true; }
        $plays = $plugin->getConfig()['_daily_plays'] ?? [];
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        return (int) ($plays[$today][$userKey] ?? 0) < $limit;
    }

    /** @param array<string, mixed> $config */
    private function welcomeAction(array $config): string
    {
        if ((int) ($config['track_id'] ?? 0) > 0) { return 'play_track'; }
        if ((int) ($config['playlist_id'] ?? 0) > 0) { return 'play_playlist'; }
        if (trim((string) ($config['radio_url'] ?? '')) !== '') { return 'play_radio'; }
        throw new \InvalidArgumentException('Welcome Song requires track_id, playlist_id, or radio_url.');
    }

    private function markTriggered(MusicbotPlugin $plugin, string $event, string $userKey): void
    {
        $config = $plugin->getConfig();
        $config['_last_trigger'] = $event;
        $config['_last_error'] = null;
        $config['_last_triggered_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $config['_daily_plays'][$today][$userKey] = (int) ($config['_daily_plays'][$today][$userKey] ?? 0) + 1;
        $plugin->setConfig($config);
        $this->entityManager->flush();
    }

    private function insideConfiguredWindow(string $window): bool
    {
        if (!preg_match('/^(?<start>[0-2]\\d:[0-5]\\d)-(?<end>[0-2]\\d:[0-5]\\d)$/', $window, $m)) { return true; }
        $now = (new \DateTimeImmutable())->format('H:i');
        return $m['start'] <= $m['end'] ? ($now >= $m['start'] && $now <= $m['end']) : ($now >= $m['start'] || $now <= $m['end']);
    }
}
