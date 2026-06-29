<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotPayloadLogSummarizer;
use App\Module\Musicbot\Application\MusicbotRuntimeStatusNormalizer;
use PHPUnit\Framework\TestCase;

final class MusicbotRuntimeSyncRegressionTest extends TestCase
{
    public function testMusicbotLogSummariesOnlyContainShortScalars(): void
    {
        $summary = MusicbotPayloadLogSummarizer::summarizeJobPayload([
            'runtime' => [
                'runtime_ready' => true,
                'state_connected' => true,
                'ts_server_connected' => true,
                'voice_client_available' => true,
                'audio_injection_ready' => true,
                'capability_status' => 'ready',
                'playback' => ['state' => 'playing', 'queue' => array_fill(0, 200, ['title' => str_repeat('x', 100)])],
                'queue' => ['items' => array_fill(0, 200, ['title' => str_repeat('x', 100)])],
                'connectors' => ['teamspeak' => ['nested' => ['object' => true]]],
            ],
        ]);

        foreach ($summary as $value) {
            self::assertTrue(is_scalar($value) || $value === null);
        }
        self::assertArrayNotHasKey('runtime_keys', $summary);
        self::assertArrayNotHasKey('playback_keys', $summary);
        self::assertArrayNotHasKey('connector_keys', $summary);
        self::assertArrayNotHasKey('source_flags', $summary);
        self::assertLessThan(2048, strlen('[musicbot-status-flow] '.json_encode(['stage' => 'test'] + $summary, JSON_THROW_ON_ERROR)));
    }

    public function testPartialPlaybackRuntimeDoesNotClearReadyStatusAfterMerge(): void
    {
        $normalizer = new MusicbotRuntimeStatusNormalizer();
        $readyRuntime = $normalizer->normalizePayload([
            'runtime_ready' => true,
            'state_connected' => true,
            'ts_server_connected' => true,
            'voice_client_available' => true,
            'audio_injection_ready' => true,
            'capability_status' => 'ready',
            'audio_backend_status' => 'ready',
            'diagnostic' => 'TeamSpeak Client Backend ist bereit',
            'playback_status' => ['playback_state' => 'playing'],
        ]);

        $merged = array_replace_recursive($readyRuntime, ['playback' => ['state' => 'paused']]);
        $normalized = $normalizer->normalizePayload($merged);

        self::assertTrue($normalized['runtime_ready']);
        self::assertTrue($normalized['teamspeak_connected']);
        self::assertTrue($normalized['audio_backend_ready']);
        self::assertSame('ready', $normalized['audio_backend_status']);
        self::assertSame('TeamSpeak Client Backend ist bereit', $normalized['diagnostic']);
        self::assertSame('paused', $normalized['playback_status']['playback_state']);
    }

    public function testNormalizerClassifiesPartialRuntimePayloads(): void
    {
        $normalizer = new MusicbotRuntimeStatusNormalizer();

        self::assertSame('partial_playback', $normalizer->classifyPayload(['runtime' => ['playback' => ['state' => 'playing']]]));
        self::assertSame('partial_queue', $normalizer->classifyPayload(['runtime' => ['queue' => ['items' => []]]]));
        self::assertSame('partial_service', $normalizer->classifyPayload(['runtime' => ['running' => true]]));
        self::assertSame('complete_runtime', $normalizer->classifyPayload(['runtime' => ['state_connected' => false, 'capability_status' => 'client_backend_required']]));
    }

    public function testPlaybackOnlySummaryReportsUnknownReadinessInsteadOfFalse(): void
    {
        $summary = MusicbotPayloadLogSummarizer::summarizeJobPayload([
            'runtime' => [
                'playback' => ['state' => 'playing', 'volume' => 80],
            ],
        ]);

        self::assertSame('unknown', $summary['runtime_ready']);
        self::assertSame('unknown', $summary['teamspeak_connected']);
        self::assertSame('unknown', $summary['audio_backend_ready']);
        self::assertSame('playing', $summary['playback_state']);
    }

    public function testAgentFinishLogUsesSymfonyLoggerInsteadOfErrorLog(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/AgentOrchestrator/UI/Controller/Agent/AgentJobController.php');
        self::assertIsString($source);
        self::assertStringContainsString('LoggerInterface', $source);
        self::assertStringContainsString("logger->debug('Agent job finished successfully.'", $source);
        self::assertStringContainsString("logger->warning('Agent job finished with failure status.'", $source);
        self::assertStringNotContainsString('error_log(', $source);
        self::assertStringContainsString("formatReadinessLogValue($"."resultSummary['runtime_ready'] ?? null, $"."job->getType())", $source);
        self::assertStringContainsString("return 'unknown';", $source);
        self::assertStringNotContainsString("($"."resultSummary['runtime_ready'] ?? false) ? 'yes' : 'no'", $source);
    }

    public function testNullableReadinessSummaryFormattingKeepsNullUnknownAndBooleansDistinct(): void
    {
        self::assertSame('unknown', MusicbotPayloadLogSummarizer::summarizeJobPayload([
            'runtime' => ['playback' => ['state' => 'playing']],
        ])['runtime_ready']);

        self::assertNull(MusicbotPayloadLogSummarizer::summarizeJobPayload([])['runtime_ready']);
        self::assertTrue(MusicbotPayloadLogSummarizer::summarizeJobPayload(['runtime_ready' => true])['runtime_ready']);
        self::assertFalse(MusicbotPayloadLogSummarizer::summarizeJobPayload(['runtime_ready' => false])['runtime_ready']);
        self::assertSame('preserved', MusicbotPayloadLogSummarizer::summarizeJobPayload(['runtime_ready' => 'preserved'])['runtime_ready']);
        self::assertSame('unknown', MusicbotPayloadLogSummarizer::summarizeJobPayload(['runtime_ready' => 'unknown'])['runtime_ready']);
    }

    public function testPartialPlaybackNormalizationDoesNotCreateFalseReadiness(): void
    {
        $normalized = (new MusicbotRuntimeStatusNormalizer())->normalizePayload([
            'runtime' => [
                'playback' => ['state' => 'paused', 'volume' => 55, 'repeat' => true, 'shuffle' => false],
                'updated_at' => '2026-06-25T00:00:00+00:00',
            ],
        ]);

        self::assertArrayNotHasKey('runtime_ready', $normalized);
        self::assertArrayNotHasKey('teamspeak_connected', $normalized);
        self::assertArrayNotHasKey('audio_backend_ready', $normalized);
        self::assertArrayNotHasKey('runtime_ready', $normalized['playback_status']);
        self::assertArrayNotHasKey('teamspeak_connected', $normalized['playback_status']);
        self::assertArrayNotHasKey('audio_backend_ready', $normalized['playback_status']);
        self::assertSame('paused', $normalized['playback_status']['playback_state']);
        self::assertSame(55, $normalized['playback_status']['volume']);
        self::assertTrue($normalized['playback_status']['repeat']);
        self::assertFalse($normalized['playback_status']['shuffle']);
    }

    public function testPartialPlaybackFlowLoggingIsDebugGatedAndDoesNotForceFalseReadiness(): void
    {
        $applierSource = file_get_contents(__DIR__.'/../../src/Module/AgentOrchestrator/Application/AgentJobResultApplier.php');
        self::assertIsString($applierSource);
        self::assertStringContainsString("MUSICBOT_DEBUG_STATUS_FLOW", $applierSource);
        self::assertStringContainsString("return;", $applierSource);
        self::assertStringNotContainsString("'teamspeak_connected' => false", $applierSource);
    }

    public function testControllersUseSameNormalizerAndPlaybackActionsRefreshStatus(): void
    {
        $adminSource = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Admin/AdminMusicbotController.php');
        $customerSource = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        $apiSource = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Api/MusicbotApiController.php');

        self::assertIsString($adminSource);
        self::assertIsString($customerSource);
        self::assertIsString($apiSource);
        self::assertStringContainsString('runtimeStatusNormalizer->normalizePayload', $adminSource);
        self::assertStringContainsString('runtimeStatusNormalizer->normalizePayload', $customerSource);
        self::assertStringContainsString('dispatchStatusRefreshJob', $apiSource);
        self::assertStringContainsString("'musicbot.status'", $apiSource);
        self::assertStringContainsString("'volume', 'seek'", $apiSource);
    }
}
