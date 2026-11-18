<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Infrastructure\Config\JsonArray;
/**
 * Builds small, safe log contexts for large Musicbot runtime/job payloads.
 *
 * These helpers intentionally never return full nested payloads, queue items,
 * track objects or runtime objects. Keep them suitable for PHP error_log/FPM stderr.
 */
final class MusicbotPayloadLogSummarizer
{
    /** @param array<array-key, mixed> $payload */
    public static function formatRuntimePayload(string $stage, ?int $instanceId, array $payload): string
    {
        $encoded = json_encode(
            ['stage' => $stage, 'instance_id' => $instanceId] + self::summarizeRuntimePayload($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return '[musicbot-status-flow] '.($encoded !== false ? $encoded : '[encoding failed]');
    }

    /**
     * @param array<array-key, mixed>|null $payload
     * @return array<array-key, mixed>
     */
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

    /**
     * @param array<array-key, mixed> $payload
     * @return array{teamspeak_connected: bool|null|string, runtime_ready: bool|null|string,
     * audio_backend_ready: bool|null|string, audio_backend_status: null|string, output_backend:
     * null|string, queue_count: int|null, playback_state: null|string, updated_at: null|string}
     */
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

    /** @param array<array-key, mixed> $payload */
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

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private static function runtimePayload(array $payload): array
    {
        return JsonArray::fromValue($payload['runtime'] ?? null) ?? $payload;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private static function arrayAt(array $payload, string $key): array
    {
        return JsonArray::orEmpty($payload[$key] ?? null);
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

    /**
     * @param array<array-key, mixed> $runtime
     * @param array<array-key, mixed> $playback
     * @param array<array-key, mixed> $queue
     */
    private static function queueCount(array $runtime, array $playback, array $queue): ?int
    {
        foreach ([MusicbotInputValue::from($runtime['queue_count'] ?? null), MusicbotInputValue::from($runtime['queue_length'] ?? null), MusicbotInputValue::from($playback['queue_count'] ?? null), MusicbotInputValue::from($playback['queue_length'] ?? null)] as $rawValue) {
            $value = MusicbotInputValue::from($rawValue);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }
        $items = JsonArray::fromValue($queue['items'] ?? null);
        return $items !== null ? count($items) : null;
    }

}
