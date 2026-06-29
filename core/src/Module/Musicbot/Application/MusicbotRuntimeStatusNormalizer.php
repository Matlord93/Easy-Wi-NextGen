<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

final class MusicbotRuntimeStatusNormalizer
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function normalizePayload(array $payload): array
    {
        $payloadKind = $this->classifyPayload($payload);
        if (str_starts_with($payloadKind, 'partial_')) {
            $status = $this->buildPartialPlaybackStatus($payload);
            if ($status !== []) {
                $payload['playback_status'] = $status + (is_array($payload['playback_status'] ?? null) ? $payload['playback_status'] : []);
                $payload['normalized_playback_status'] = $status;
            }
            $payload['last_runtime_sync'] = $payload['last_runtime_sync'] ?? $payload['updated_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            return $payload;
        }

        $status = $this->buildPlaybackStatus($payload);
        $payload['playback_status'] = $status + (is_array($payload['playback_status'] ?? null) ? $payload['playback_status'] : []);
        // Ensure normalized values win over stale legacy fields in downstream templates.
        foreach ($status as $key => $value) {
            if (in_array($key, ['connected','state_connected','ts_server_connected','voice_client_available','capability_status','audio_injection_ready','audio_backend_ready','audio_backend_status','audio_backend_message','diagnostic','runtime_ready','teamspeak_connected','backend','active_backend','output_backend'], true)) {
                $payload['playback_status'][$key] = $value;
            }
        }
        $payload['runtime_ready'] = $status['runtime_ready'];
        $payload['teamspeak_connected'] = $status['teamspeak_connected'];
        $payload['audio_backend_ready'] = $status['audio_backend_ready'];
        $payload['audio_backend_status'] = $status['audio_backend_status'];
        $payload['diagnostic'] = $status['diagnostic'];
        $payload = $this->normalizeRuntimeErrors($payload, $status);
        $payload['normalized_teamSpeak_connected'] = $status['teamspeak_connected'];
        $payload['normalized_audio_backend_ready'] = $status['audio_backend_ready'];
        $payload['normalized_audio_backend_message'] = $status['audio_backend_message'];
        $payload['normalized_playback_status'] = $status;
        $payload['last_runtime_sync'] = $payload['last_runtime_sync'] ?? $payload['updated_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function classifyPayload(array $payload): string
    {
        $runtime = is_array($payload['runtime'] ?? null) ? $payload['runtime'] : $payload;
        $hasReadiness = false;
        foreach (['runtime_ready', 'state_connected', 'ts_server_connected', 'teamspeak_connected', 'voice_client_available', 'audio_injection_ready', 'audio_backend_ready', 'capability_status'] as $key) {
            if (array_key_exists($key, $runtime)) {
                $hasReadiness = true;
                break;
            }
        }
        if ($hasReadiness) {
            return 'complete_runtime';
        }
        if (array_key_exists('playback_status', $runtime) && is_array($runtime['playback_status'])) {
            foreach (['audio_backend_ready', 'audio_backend_status', 'audio_backend_message', 'audio_injection_ready', 'runtime_ready'] as $key) {
                if (array_key_exists($key, $runtime['playback_status'])) {
                    return 'complete_runtime';
                }
            }
        }
        if (array_key_exists('playback', $runtime) || array_key_exists('playback_status', $runtime) || array_key_exists('playback_state', $runtime)) {
            return 'partial_playback';
        }
        if (array_key_exists('queue', $runtime) || array_key_exists('queue_count', $runtime) || array_key_exists('queue_length', $runtime)) {
            return 'partial_queue';
        }
        if (array_key_exists('service', $runtime) || array_key_exists('running', $runtime) || array_key_exists('status', $runtime)) {
            return 'partial_service';
        }

        return 'complete_runtime';
    }

    /** @param array<string, mixed> $payload */
    public function isPartialPayload(array $payload): bool
    {
        return str_starts_with($this->classifyPayload($payload), 'partial_');
    }

    /**
     * Normalise the connector map (connectors.teamspeak / connectors.discord)
     * into the flat readiness fields used by downstream templates.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizeConnectorStatuses(array $payload): array
    {
        $connectors = is_array($payload['connectors'] ?? null) ? $payload['connectors'] : [];

        $teamspeak = is_array($connectors['teamspeak'] ?? null) ? $connectors['teamspeak'] : [];
        $discord   = is_array($connectors['discord']   ?? null) ? $connectors['discord']   : [];

        $payload['connector_statuses'] = [
            'teamspeak' => [
                'enabled'           => (bool) ($teamspeak['enabled'] ?? false),
                'state'             => (string) ($teamspeak['state'] ?? 'disconnected'),
                'connected'         => (bool) ($teamspeak['connected'] ?? false),
                'audio_ready'       => $this->truthy($teamspeak['voice_client_available'] ?? false)
                                       && (($teamspeak['capability_status'] ?? '') === 'ready'),
                'current_channel'   => (string) ($teamspeak['channel_id'] ?? ''),
                'capability_status' => (string) ($teamspeak['capability_status'] ?? ''),
                'output_backend'    => (string) ($teamspeak['output_backend'] ?? 'null'),
                'diagnostics'       => $teamspeak,
            ],
            'discord' => [
                'enabled'           => (bool) ($discord['enabled'] ?? false),
                'state'             => (string) ($discord['state'] ?? 'disconnected'),
                'connected'         => (bool) ($discord['connected'] ?? false),
                'audio_ready'       => $this->truthy($discord['voice_client_available'] ?? false)
                                       && (($discord['capability_status'] ?? '') === 'ready'),
                'current_channel'   => (string) ($discord['channel_id'] ?? ''),
                'capability_status' => (string) ($discord['capability_status'] ?? ''),
                'output_backend'    => (string) ($discord['output_backend'] ?? 'null'),
                'diagnostics'       => $discord,
            ],
        ];

        // Promote connector.teamspeak fields into the flat legacy fields so
        // existing templates keep working without changes.
        if ($teamspeak !== []) {
            $payload['state_connected']        = $payload['state_connected']        ?? ($teamspeak['connected'] ?? false);
            $payload['ts_server_connected']    = $payload['ts_server_connected']    ?? ($teamspeak['connected'] ?? false);
            $payload['voice_client_available'] = $payload['voice_client_available'] ?? ($teamspeak['voice_client_available'] ?? false);
            $payload['capability_status']      = $payload['capability_status']      ?? ($teamspeak['capability_status'] ?? '');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function buildPlaybackStatus(array $payload): array
    {
        // Hydrate flat legacy fields from connectors map before reading below.
        $payload = $this->normalizeConnectorStatuses($payload);

        $playback = is_array($payload['playback'] ?? null) ? $payload['playback'] : [];
        $pipeline = is_array($payload['audio_pipeline'] ?? null) ? $payload['audio_pipeline'] : [];
        $status = is_array($payload['playback_status'] ?? null) ? $payload['playback_status'] : [];

        $read = static function (string $key) use ($payload, $status, $pipeline, $playback): mixed {
            return $payload[$key] ?? $status[$key] ?? $pipeline[$key] ?? $playback[$key] ?? null;
        };

        // Runtime readiness must come from the current runtime payload (or promoted
        // connector fields), not from stale legacy playback_status snapshots. A
        // persisted playback_status alone may describe an older ready state and
        // must not make a fresh payload ready without the canonical runtime
        // readiness fields.
        $stateConnected = $this->truthy($payload['state_connected'] ?? $pipeline['state_connected'] ?? $playback['state_connected'] ?? null);
        $tsServerConnected = $this->truthy($payload['ts_server_connected'] ?? $pipeline['ts_server_connected'] ?? $playback['ts_server_connected'] ?? null);
        $voiceClientAvailable = $this->truthy($payload['voice_client_available'] ?? $pipeline['voice_client_available'] ?? $playback['voice_client_available'] ?? null);
        $audioInjectionReady = $this->truthy($payload['audio_injection_ready'] ?? $pipeline['audio_injection_ready'] ?? $playback['audio_injection_ready'] ?? null);
        $capability = strtolower((string) ($payload['capability_status'] ?? $pipeline['capability_status'] ?? $playback['capability_status'] ?? ''));
        $runtimeReady = $stateConnected
            && $tsServerConnected
            && $voiceClientAvailable
            && $audioInjectionReady
            && $capability === 'ready';

        $audioBackendStatus = strtolower((string) ($read('audio_backend_status') ?? ''));
        if ($runtimeReady) {
            $teamspeakConnected = true;
            $audioReady = true;
            $audioBackendStatus = 'ready';
            $audioMessage = 'TeamSpeak Client Backend ist bereit';
            $diagnostic = $audioMessage;
            $capability = 'ready';
        } else {
            $teamspeakConnected = false;
            $audioReady = false;
            $audioBackendStatus = $audioBackendStatus !== '' && $audioBackendStatus !== 'ready' ? $audioBackendStatus : 'not_ready';
            $audioMessage = (string) ($read('audio_backend_message') ?? '');
            $diagnostic = (string) ($read('diagnostic') ?? 'Wartet auf Runtime');
            $capability = $capability !== '' ? $capability : 'client_backend_required';
        }

        $outputBackend = $read('output_backend');

        return [
            'playback_state' => (string) ($playback['state'] ?? $payload['playback_state'] ?? $status['playback_state'] ?? 'unknown'),
            'queue_count' => (int) ($payload['queue_count'] ?? (is_array($payload['queue']['items'] ?? null) ? count($payload['queue']['items']) : 0)),
            'connected' => $runtimeReady,
            'state_connected' => $stateConnected,
            'ts_server_connected' => $tsServerConnected,
            'teamspeak_connected' => $teamspeakConnected,
            'voice_client_available' => $voiceClientAvailable,
            'capability_status' => $capability,
            'runtime_ready' => $runtimeReady,
            'backend' => (string) ($read('backend') ?? ''),
            'active_backend' => (string) ($read('active_backend') ?? ''),
            'output_backend' => $outputBackend === null || $outputBackend === '' ? 'null' : (string) $outputBackend,
            'output_status' => (string) ($status['output_status'] ?? $pipeline['output_status'] ?? 'idle'),
            'decoder_status' => (string) ($status['decoder_status'] ?? $pipeline['decoder_status'] ?? ''),
            'audio_backend_ready' => $audioReady,
            'audio_injection_ready' => $audioInjectionReady,
            'audio_backend_status' => $audioBackendStatus,
            'audio_backend_message' => $audioMessage,
            'diagnostic' => $diagnostic,
            'source_connected_field' => $runtimeReady ? 'runtime_ready_definition' : null,
            'source_audio_ready_field' => $runtimeReady ? 'runtime_ready_definition' : null,
            'source_output_backend_field' => $outputBackend !== null ? 'output_backend' : null,
        ];
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $status @return array<string, mixed> */
    private function normalizeRuntimeErrors(array $payload, array $status): array
    {
        $playbackStatus = is_array($payload['playback_status'] ?? null) ? $payload['playback_status'] : [];
        $candidate = $this->firstNonEmptyString($payload['last_error'] ?? null, $payload['error'] ?? null, $playbackStatus['last_error'] ?? null);
        $active = $this->resolveActiveLastError($payload, $status);

        if ($candidate !== null && $active === null && $this->isRuntimeReady($status) && $this->isStaleRuntimeError($candidate)) {
            $payload['last_historical_error'] = $candidate;
            unset($payload['last_error'], $payload['error']);
            unset($playbackStatus['last_error']);
        }

        $payload['active_last_error'] = $active;
        $playbackStatus['active_last_error'] = $active;
        if ($active === null) {
            unset($payload['active_last_error'], $playbackStatus['active_last_error']);
        }
        $payload['playback_status'] = $playbackStatus;

        return $payload;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed>|null $status */
    public function resolveActiveLastError(array $payload, ?array $status = null): ?string
    {
        $playbackStatus = is_array($payload['playback_status'] ?? null) ? $payload['playback_status'] : [];
        $status = $status ?? $playbackStatus;
        $candidate = $this->firstNonEmptyString($payload['last_error'] ?? null, $payload['error'] ?? null, $playbackStatus['last_error'] ?? null);
        if ($candidate === null) {
            return null;
        }

        if ($this->hasErrorState($status)) {
            return $candidate;
        }

        if (!$this->isRuntimeReady($status)) {
            return $candidate;
        }

        return null;
    }

    /** @param array<string, mixed> $status */
    private function hasErrorState(array $status): bool
    {
        foreach (['playback_state', 'decoder_status', 'output_status'] as $key) {
            if (strtolower(trim((string) ($status[$key] ?? ''))) === 'error') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $status */
    private function isRuntimeReady(array $status): bool
    {
        return ($status['runtime_ready'] ?? false) === true;
    }

    private function isStaleRuntimeError(string $error): bool
    {
        $normalized = strtolower($error);
        foreach ([
            'runtime control socket unavailable',
            'command queued as state file',
            'waiting for runtime',
            'backend fehlt',
            'not ready',
            'waiting for teamspeak runtime-status',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $queueItem
     * @return array<string, mixed>
     */
    public function buildNowPlaying(array $payload, ?array $queueItem = null): array
    {
        $payload = $this->normalizePayload($payload);
        $playback = is_array($payload['playback'] ?? null) ? $payload['playback'] : [];
        $status = is_array($payload['playback_status'] ?? null) ? $payload['playback_status'] : [];
        $pipeline = is_array($payload['audio_pipeline'] ?? null) ? $payload['audio_pipeline'] : [];
        $currentTrack = is_array($playback['current_track'] ?? null) ? $playback['current_track'] : [];
        $playbackState = strtolower((string) ($status['playback_state'] ?? $playback['state'] ?? $payload['playback_state'] ?? 'stopped'));
        $currentTrackSourceType = $this->sourceTypeFromTrack($currentTrack);
        $payloadSourceType = $this->normalizeSourceType($this->stringValue($payload['source_type'] ?? null));
        $pipelineSourceType = $this->normalizeSourceType($this->stringValue($pipeline['source_type'] ?? null));
        $statusSourceType = $this->normalizeSourceType($this->firstNonEmptyString(
            $this->stringValue($status['current_source'] ?? null),
            $this->stringValue($status['source_type'] ?? null)
        ));
        $pipelineUrl = $this->firstNonEmptyString($this->stringValue($pipeline['url'] ?? null), $this->stringValue($pipeline['stream_url'] ?? null));
        $isRuntimeRadio = $playbackState === 'playing' && (
            in_array($payloadSourceType, ['radio', 'stream'], true)
            || in_array($statusSourceType, ['radio', 'stream'], true)
            || in_array($pipelineSourceType, ['radio', 'stream'], true)
            || ($this->isHttpUrl($pipelineUrl) && in_array($pipelineSourceType !== '' ? $pipelineSourceType : ($statusSourceType !== '' ? $statusSourceType : $payloadSourceType), ['radio', 'stream'], true))
        );

        if ($isRuntimeRadio) {
            $queueSourceType = $queueItem !== null ? $this->normalizeSourceType($this->stringValue($queueItem['source_type'] ?? null)) : '';
            $url = $this->firstNonEmptyString(
                $pipelineUrl,
                $this->stringValue($status['current_url'] ?? null),
                $this->stringValue($status['url'] ?? null),
                $this->stringValue($status['stream_url'] ?? null)
            );
            $currentTrackTitle = $this->stringValue($currentTrack['title'] ?? null);
            $statusTitle = $this->radioStatusTitle($status, $currentTrack, $currentTrackSourceType);
            $title = $this->firstNonEmptyString(
                in_array($currentTrackSourceType, ['radio', 'stream'], true) ? $currentTrackTitle : null,
                in_array($queueSourceType, ['radio', 'stream'], true) ? $this->stringValue($queueItem['title'] ?? null) : null,
                $statusTitle,
                $this->stringValue($status['station_name'] ?? null),
                $this->stringValue($status['current_station'] ?? null),
                $this->stationNameFromUrl($url),
                'Webradio'
            );

            return [
                'title' => $title,
                'artist' => $this->firstNonEmptyString($this->stringValue($status['current_artist'] ?? null), in_array($queueSourceType, ['radio', 'stream'], true) ? $this->stringValue($queueItem['artist'] ?? null) : null) ?? '',
                'source_type' => 'radio',
                'source_label' => 'Webradio',
                'url' => $url ?? '',
                'thumbnail' => $this->firstNonEmptyString($this->stringValue($status['thumbnail'] ?? null), $this->stringValue($status['thumbnail_url'] ?? null), $this->stringValue($status['logo'] ?? null), $this->stringValue($status['station_logo'] ?? null), in_array($queueSourceType, ['radio', 'stream'], true) ? $this->stringValue($queueItem['thumbnail'] ?? null) : null) ?? '',
                'is_live' => true,
                'queue_item_id' => in_array($queueSourceType, ['radio', 'stream'], true) ? ($this->stringValue($queueItem['queue_item_id'] ?? null) ?? '') : '',
                'track_id' => in_array($queueSourceType, ['radio', 'stream'], true) ? ($this->stringValue($queueItem['track_id'] ?? null) ?? '') : '',
                'playback_state' => $playbackState,
            ];
        }

        $sourceType = $this->normalizeSourceType($this->firstNonEmptyString(
            $currentTrackSourceType,
            $this->stringValue($status['current_source'] ?? null),
            $this->stringValue($status['source_type'] ?? null),
            $this->stringValue($payload['source_type'] ?? null)
        ));

        $title = $this->firstNonEmptyString($this->stringValue($currentTrack['title'] ?? null), $this->stringValue($status['current_title'] ?? null));
        $artist = $this->firstNonEmptyString($this->stringValue($currentTrack['artist'] ?? null), $this->stringValue($status['current_artist'] ?? null));
        $url = $this->firstNonEmptyString($this->stringValue($currentTrack['url'] ?? null), $this->stringValue($currentTrack['uri'] ?? null), $this->stringValue($status['current_url'] ?? null), $this->stringValue($status['url'] ?? null), $this->stringValue($status['stream_url'] ?? null));
        $thumbnail = $this->firstNonEmptyString($this->stringValue($currentTrack['thumbnail'] ?? null), $this->stringValue($currentTrack['thumbnail_url'] ?? null), $this->stringValue($status['thumbnail'] ?? null), $this->stringValue($status['thumbnail_url'] ?? null), $this->stringValue($status['logo'] ?? null), $this->stringValue($status['station_logo'] ?? null));
        $queueItemId = $this->firstNonEmptyString($this->stringValue($currentTrack['queue_item_id'] ?? null), $this->stringValue($status['current_queue_item_id'] ?? null));
        $trackId = $this->firstNonEmptyString($this->stringValue($currentTrack['track_id'] ?? null), $this->stringValue($currentTrack['id'] ?? null), $this->stringValue($status['current_track_id'] ?? null));

        if ($title === null && $sourceType === '' && $queueItem !== null) {
            $title = $this->stringValue($queueItem['title'] ?? null);
            $artist ??= $this->stringValue($queueItem['artist'] ?? null);
            $sourceType = $sourceType !== '' ? $sourceType : $this->normalizeSourceType($this->stringValue($queueItem['source_type'] ?? null));
            $url ??= $this->stringValue($queueItem['url'] ?? null);
            $thumbnail ??= $this->stringValue($queueItem['thumbnail'] ?? null);
            $queueItemId ??= $this->stringValue($queueItem['queue_item_id'] ?? null);
            $trackId ??= $this->stringValue($queueItem['track_id'] ?? null);
        }

        if (!in_array($playbackState, ['playing', 'paused'], true)) {
            $title = $artist = $url = $thumbnail = $queueItemId = $trackId = null;
            $sourceType = '';
        }

        if ($sourceType === 'radio' && $title === null) {
            $title = $this->firstNonEmptyString($this->stringValue($status['station_name'] ?? null), $this->stringValue($status['current_station'] ?? null), $url, 'Webradio');
        }

        return [
            'title' => $title ?? '',
            'artist' => $artist ?? '',
            'source_type' => $sourceType,
            'source_label' => $this->sourceLabel($sourceType),
            'url' => $url ?? '',
            'thumbnail' => $thumbnail ?? '',
            'is_live' => in_array($sourceType, ['radio', 'stream'], true),
            'queue_item_id' => $queueItemId ?? '',
            'track_id' => $trackId ?? '',
            'playback_state' => $playbackState,
        ];
    }

    private function normalizeSourceType(?string $source): string
    {
        $source = strtolower(trim((string) $source));
        return match ($source) {
            'webradio', 'radio' => 'radio',
            'yt', 'youtube' => 'youtube',
            'local', 'local_file', 'file', 'upload' => $source === 'upload' ? 'upload' : 'local_file',
            'stream', 'url' => $source,
            default => $source,
        };
    }

    /** @param array<string, mixed> $status @param array<string, mixed> $currentTrack */
    private function radioStatusTitle(array $status, array $currentTrack, string $currentTrackSource): ?string
    {
        $title = $this->stringValue($status['current_title'] ?? null);
        if ($title === null) {
            return null;
        }

        $statusSource = $this->normalizeSourceType($this->firstNonEmptyString(
            $this->stringValue($status['current_source'] ?? null),
            $this->stringValue($status['source_type'] ?? null)
        ));
        if (in_array($statusSource, ['upload', 'local_file'], true)) {
            return null;
        }

        $currentTrackTitle = $this->stringValue($currentTrack['title'] ?? null);
        if (in_array($currentTrackSource, ['upload', 'local_file'], true) && $currentTrackTitle !== null && $this->sameTitle($title, $currentTrackTitle)) {
            return null;
        }

        return $title;
    }

    /** @param array<string, mixed> $currentTrack */
    private function sourceTypeFromTrack(array $currentTrack): string
    {
        $source = is_array($currentTrack['source'] ?? null) ? $currentTrack['source'] : [];

        return $this->normalizeSourceType($this->firstNonEmptyString(
            $this->stringValue($currentTrack['source_type'] ?? null),
            $this->stringValue($source['type'] ?? null),
            $this->stringValue($currentTrack['source'] ?? null)
        ));
    }

    private function sameTitle(string $left, string $right): bool
    {
        return strtolower(trim($left)) === strtolower(trim($right));
    }

    private function sourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'radio' => 'Webradio',
            'youtube' => 'YouTube',
            'upload', 'local_file' => 'Upload',
            'stream', 'url' => 'Stream',
            default => $sourceType !== '' ? ucfirst($sourceType) : '',
        };
    }

    private function isHttpUrl(?string $url): bool
    {
        return is_string($url) && preg_match('/^https?:\/\//i', $url) === 1;
    }

    private function stationNameFromUrl(?string $url): ?string
    {
        if (!$this->isHttpUrl($url)) {
            return null;
        }

        $host = parse_url((string) $url, PHP_URL_HOST);
        if (!is_string($host) || trim($host) === '') {
            return null;
        }

        $parts = array_values(array_filter(explode('.', strtolower($host)), static fn (string $part): bool => $part !== '' && !in_array($part, ['www', 'listen', 'stream', 'radio'], true)));
        if (count($parts) < 2) {
            return ucfirst($parts[0] ?? $host);
        }

        $name = $parts[count($parts) - 2];
        $tld = $parts[count($parts) - 1];

        return ucfirst($name).'.'.strtoupper($tld);
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_scalar($value)) {
            $value = trim((string) $value);
            return $value !== '' ? $value : null;
        }

        return null;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function buildPartialPlaybackStatus(array $payload): array
    {
        $runtime = is_array($payload['runtime'] ?? null) ? $payload['runtime'] : $payload;
        $playback = is_array($runtime['playback'] ?? null) ? $runtime['playback'] : [];
        $status = is_array($runtime['playback_status'] ?? null) ? $runtime['playback_status'] : [];
        $queue = is_array($runtime['queue'] ?? null) ? $runtime['queue'] : [];
        $partial = [];

        $playbackState = $playback['state'] ?? $runtime['playback_state'] ?? $status['playback_state'] ?? $status['state'] ?? null;
        if ($playbackState !== null) {
            $partial['playback_state'] = (string) $playbackState;
        }

        foreach (['queue_count', 'volume', 'repeat', 'shuffle', 'updated_at'] as $key) {
            if (array_key_exists($key, $runtime)) {
                $partial[$key] = $runtime[$key];
            } elseif (array_key_exists($key, $playback)) {
                $partial[$key] = $playback[$key];
            } elseif (array_key_exists($key, $status)) {
                $partial[$key] = $status[$key];
            }
        }

        if (!array_key_exists('queue_count', $partial) && is_array($queue['items'] ?? null)) {
            $partial['queue_count'] = count($queue['items']);
        }

        return $partial;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) { return $value; }
        if (is_int($value) || is_float($value)) { return $value !== 0; }
        if (is_string($value)) { return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'ready', 'connected'], true); }
        return false;
    }
}
