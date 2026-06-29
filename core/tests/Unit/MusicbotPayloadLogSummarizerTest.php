<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotPayloadLogSummarizer;
use PHPUnit\Framework\TestCase;

final class MusicbotPayloadLogSummarizerTest extends TestCase
{
    public function testLargeRuntimePayloadIsSummarizedWithoutFullJsonObjects(): void
    {
        $payload = [
            'runtime' => [
                'state_connected' => true,
                'ts_server_connected' => true,
                'voice_client_available' => true,
                'audio_injection_ready' => true,
                'capability_status' => 'ready',
                'output_backend' => 'teamspeak_voice',
                'updated_at' => '2026-06-25T12:00:00+00:00',
                'queue' => ['items' => array_fill(0, 100, ['id' => 'queue-secret-item', 'title' => str_repeat('Q', 200)])],
                'playback_status' => [
                    'playback_state' => 'playing',
                    'audio_backend_ready' => true,
                    'audio_backend_status' => 'ready',
                    'audio_backend_message' => 'TeamSpeak Client Backend ist bereit',
                ],
                'track' => ['title' => str_repeat('TRACK', 500)],
            ],
        ];

        $summary = MusicbotPayloadLogSummarizer::summarizeJobPayload($payload);
        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);

        self::assertSame('teamspeak_voice', $summary['output_backend']);
        self::assertSame(100, $summary['queue_count']);
        self::assertTrue($summary['runtime_ready']);
        self::assertTrue($summary['audio_backend_ready']);
        self::assertStringNotContainsString('queue-secret-item', $encoded);
        self::assertStringNotContainsString(str_repeat('TRACK', 20), $encoded);
        self::assertLessThan(2500, strlen($encoded));
    }

    public function testPlaybackStatusSummaryKeepsOnlyCompactMetadata(): void
    {
        $summary = MusicbotPayloadLogSummarizer::summarizePlaybackStatus([
            'playback_state' => 'paused',
            'current_track' => ['title' => str_repeat('x', 1000)],
            'audio_backend_ready' => true,
            'audio_backend_status' => 'ready',
            'audio_backend_message' => str_repeat('diagnostic ', 100),
            'output_backend' => 'teamspeak_voice',
            'queue_count' => 2,
        ]);

        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        self::assertSame('paused', $summary['playback_state']);
        self::assertSame(2, $summary['queue_count']);
        self::assertStringNotContainsString(str_repeat('x', 100), $encoded);
        self::assertLessThanOrEqual(160, strlen((string) $summary['audio_backend_message']));
    }
}
