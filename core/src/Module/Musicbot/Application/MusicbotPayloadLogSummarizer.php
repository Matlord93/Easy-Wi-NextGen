<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

/**
 * Builds small, safe log contexts for large Musicbot runtime/job payloads.
 *
 * These helpers intentionally never return full nested payloads, queue items,
 * track objects or runtime objects. Keep them suitable for PHP error_log/FPM stderr.
 */
final class MusicbotPayloadLogSummarizer
{
    /** @param array<string, mixed>|null $payload @return array<string, mixed> */
    public static function summarizeJobPayload(?array $payload): array
    {
        $payload ??= [];
        $runtime = self::runtimePayload($payload);
        $summary = self::summarizeRuntimePayload($runtime);
        if (in_array(self::classifyPayload($runtime), ['partial_playback', 'partial_queue', 'partial_service'], true)) {
            foreach (['teamspeak_connected', 'runtime_ready', 'audio_backend_ready'] as $key) {
                if (($summary[$key] ?? null) === null) {
                    $summary[$key] = 'unknown';
                }
            }
        }

        return $summary;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function summarizeRuntimePayload(array $payload): array
    {
        $runtime = self::runtimePayload($payload);
        $playback = self::arrayAt($runtime, 'playback_status') ?: self::arrayAt($runtime, 'playback');
        $audioPipeline = self::arrayAt($runtime, 'audio_pipeline');
        $queue = self::arrayAt($runtime, 'queue');
        $teamspeak = self::arrayAt($runtime, 'teamspeak');
        $teamspeakConnected = self::nullableBoolValue(
            $playback['teamspeak_connected'] ?? $runtime['teamspeak_connected'] ?? $runtime['ts_server_connected'] ?? $teamspeak['connected'] ?? null
        );
        $runtimeReady = self::nullableBoolValue($runtime['runtime_ready'] ?? $runtime['state_connected'] ?? null);
        if ($runtimeReady !== true && ($runtime['capability_status'] ?? $playback['capability_status'] ?? null) === 'ready') {
            $runtimeReady = true;
        }
        $audioBackendReady = self::nullableBoolValue($playback['audio_backend_ready'] ?? $runtime['audio_backend_ready'] ?? $runtime['audio_injection_ready'] ?? $audioPipeline['ready'] ?? null);

        return [
            'teamspeak_connected' => $teamspeakConnected,
            'runtime_ready' => $runtimeReady,
            'audio_backend_ready' => $audioBackendReady,
            'audio_backend_status' => self::shortString($playback['audio_backend_status'] ?? $runtime['audio_backend_status'] ?? $audioPipeline['status'] ?? null),
            'output_backend' => self::shortString($playback['output_backend'] ?? $runtime['output_backend'] ?? $audioPipeline['output_backend'] ?? null),
            'queue_count' => self::queueCount($runtime, $playback, $queue),
            'playback_state' => self::shortString($playback['playback_state'] ?? $playback['state'] ?? $runtime['playback_state'] ?? null),
            'updated_at' => self::shortString($runtime['updated_at'] ?? $runtime['heartbeat_at'] ?? $playback['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $status @return array<string, mixed> */
    public static function summarizePlaybackStatus(array $status): array
    {
        return [
            'teamspeak_connected' => self::nullableBoolValue($status['teamspeak_connected'] ?? null),
            'audio_backend_ready' => self::nullableBoolValue($status['audio_backend_ready'] ?? null),
            'audio_backend_status' => self::shortString($status['audio_backend_status'] ?? null),
            'audio_backend_message' => self::shortString($status['audio_backend_message'] ?? null),
            'output_backend' => self::shortString($status['output_backend'] ?? null),
            'queue_count' => isset($status['queue_count']) ? (int) $status['queue_count'] : null,
            'playback_state' => self::shortString($status['playback_state'] ?? $status['state'] ?? null),
            'updated_at' => self::shortString($status['updated_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function classifyPayload(array $payload): string
    {
        foreach (['runtime_ready', 'state_connected', 'ts_server_connected', 'teamspeak_connected', 'voice_client_available', 'audio_injection_ready', 'audio_backend_ready', 'capability_status'] as $key) {
            if (array_key_exists($key, $payload)) {
                return 'complete_runtime';
            }
        }
        if (array_key_exists('playback', $payload) || array_key_exists('playback_status', $payload) || array_key_exists('playback_state', $payload)) {
            return 'partial_playback';
        }
        if (array_key_exists('queue', $payload) || array_key_exists('queue_count', $payload) || array_key_exists('queue_length', $payload)) {
            return 'partial_queue';
        }
        if (array_key_exists('service', $payload) || array_key_exists('running', $payload) || array_key_exists('status', $payload)) {
            return 'partial_service';
        }

        return 'complete_runtime';
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function runtimePayload(array $payload): array
    {
        return is_array($payload['runtime'] ?? null) ? $payload['runtime'] : $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function arrayAt(array $payload, string $key): array
    {
        return is_array($payload[$key] ?? null) ? $payload[$key] : [];
    }

    private static function nullableBoolValue(mixed $value): bool|string|null
    {
        if ($value === null) {
            return null;
        }
        if ($value === true || $value === false) {
            return $value;
        }
        if ($value === 'preserved' || $value === 'unknown') {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    private static function shortString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $text = trim((string) $value);
        return mb_substr($text, 0, 160);
    }

    /** @param array<string, mixed> $runtime @param array<string, mixed> $playback @param array<string, mixed> $queue */
    private static function queueCount(array $runtime, array $playback, array $queue): ?int
    {
        foreach ([$runtime['queue_count'] ?? null, $runtime['queue_length'] ?? null, $playback['queue_count'] ?? null, $playback['queue_length'] ?? null] as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }
        return is_array($queue['items'] ?? null) ? count($queue['items']) : null;
    }

}
