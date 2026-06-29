<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotRuntimeStatusNormalizer;
use PHPUnit\Framework\TestCase;

final class MusicbotRuntimeReadinessRegressionTest extends TestCase
{
    public function testRuntimeReadyPayloadWithEmptyQueueAndNullOutputIsAudioReady(): void
    {
        $status = (new MusicbotRuntimeStatusNormalizer())->buildPlaybackStatus([
            'state_connected' => true,
            'capability_status' => 'ready',
            'ts_server_connected' => true,
            'voice_client_available' => true,
            'audio_injection_ready' => true,
            'output_backend' => null,
            'queue_count' => 0,
            'playback' => ['state' => 'idle'],
        ]);

        self::assertTrue($status['teamspeak_connected']);
        self::assertTrue($status['audio_backend_ready']);
        self::assertSame('ready', $status['audio_backend_status']);
        self::assertSame('TeamSpeak Client Backend ist bereit', $status['audio_backend_message']);
        self::assertSame('null', $status['output_backend']);
        self::assertSame(0, $status['queue_count']);
    }

    public function testAudioBackendReadyNormalizesForAdminAndCustomerConsumers(): void
    {
        $normalizer = new MusicbotRuntimeStatusNormalizer();
        $payload = $normalizer->normalizePayload(['state_connected' => true, 'ts_server_connected' => true, 'voice_client_available' => true, 'audio_injection_ready' => true, 'capability_status' => 'ready']);

        self::assertTrue($payload['playback_status']['audio_backend_ready']);
        self::assertSame('ready', $payload['playback_status']['audio_backend_status']);
        self::assertSame('TeamSpeak Client Backend ist bereit', $payload['playback_status']['audio_backend_message']);
        self::assertTrue($payload['normalized_audio_backend_ready']);
    }

    public function testRuntimeReadyWinsOverOldMissingConnectionFields(): void
    {
        $payload = (new MusicbotRuntimeStatusNormalizer())->normalizePayload([
            'state_connected' => true,
            'ts_server_connected' => true,
            'voice_client_available' => true,
            'audio_injection_ready' => true,
            'capability_status' => 'ready',
            'connected' => false,
            'playback_status' => ['audio_backend_ready' => false],
        ]);

        self::assertTrue($payload['playback_status']['audio_backend_ready']);
        self::assertSame('ready', $payload['playback_status']['audio_backend_status']);
        self::assertSame('TeamSpeak Client Backend ist bereit', $payload['playback_status']['audio_backend_message']);
        self::assertTrue($payload['normalized_audio_backend_ready']);
    }

    public function testAdminAndCustomerTemplatesUseSameNormalizedRuntimeTerms(): void
    {
        $admin = file_get_contents(__DIR__.'/../../templates/admin/musicbot/show.html.twig');
        $customer = file_get_contents(__DIR__.'/../../templates/customer/musicbot/show.html.twig');
        self::assertIsString($admin);
        self::assertIsString($customer);

        foreach (['runtimeAudioReady', 'TeamSpeak Client Backend ist bereit', 'Queue leer. Der Bot hat nichts abzuspielen.'] as $needle) {
            self::assertStringContainsString($needle, $admin);
            self::assertStringContainsString($needle, $customer);
        }
        self::assertStringNotContainsString('TeamSpeak muss verbunden und Backend eingerichtet sein', $admin.$customer);
    }

    public function testPartialRuntimePayloadMergePreservesExistingReadinessSemantics(): void
    {
        $normalizer = new MusicbotRuntimeStatusNormalizer();
        $existing = $normalizer->normalizePayload([
            'state_connected' => true,
            'capability_status' => 'ready',
            'ts_server_connected' => true,
            'voice_client_available' => true,
            'audio_injection_ready' => true,
        ]);
        $partial = ['playback' => ['state' => 'stopped']];
        $merged = array_replace_recursive($existing, $partial);
        $payload = $normalizer->normalizePayload($merged);

        self::assertTrue($payload['playback_status']['teamspeak_connected']);
        self::assertTrue($payload['playback_status']['audio_backend_ready']);
        self::assertSame('stopped', $payload['playback_status']['playback_state']);
    }

    public function testEmptyQueueAndMissingOutputDoNotCreateBackendFailure(): void
    {
        $status = (new MusicbotRuntimeStatusNormalizer())->buildPlaybackStatus([
            'state_connected' => true,
            'ts_server_connected' => true,
            'voice_client_available' => true,
            'capability_status' => 'ready',
            'audio_injection_ready' => true,
            'audio_pipeline' => ['output_backend' => null],
        ]);

        self::assertTrue($status['teamspeak_connected']);
        self::assertTrue($status['audio_backend_ready']);
        self::assertSame('null', $status['output_backend']);
        self::assertSame('TeamSpeak Client Backend ist bereit', $status['audio_backend_message']);
    }

    public function testLegacyFieldsCannotOverrideRuntimeReady(): void
    {
        $payload = (new MusicbotRuntimeStatusNormalizer())->normalizePayload([
            'connected' => false,
            'state_connected' => true,
            'ts_server_connected' => true,
            'capability_status' => 'ready',
            'voice_client_available' => true,
            'audio_injection_ready' => true,
            'playback_status' => ['audio_backend_ready' => false],
        ]);

        self::assertTrue($payload['playback_status']['teamspeak_connected']);
        self::assertSame('ready', $payload['playback_status']['capability_status']);
        self::assertTrue($payload['playback_status']['audio_backend_ready']);
    }


    /**
     * @dataProvider runtimeReadinessScenarioProvider
     * @param array<string, mixed> $payload
     */
    public function testRuntimeReadinessScenarios(string $scenario, array $payload, bool $ready): void
    {
        $normalized = (new MusicbotRuntimeStatusNormalizer())->normalizePayload($payload);

        self::assertSame($ready, $normalized['runtime_ready'], $scenario);
        self::assertSame($ready, $normalized['playback_status']['teamspeak_connected'], $scenario);
        self::assertSame($ready, $normalized['playback_status']['audio_backend_ready'], $scenario);
        self::assertSame($ready ? 'ready' : 'not_ready', $normalized['playback_status']['audio_backend_status'], $scenario);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}> */
    public static function runtimeReadinessScenarioProvider(): array
    {
        $ready = [
            'state_connected' => true,
            'ts_server_connected' => true,
            'voice_client_available' => true,
            'audio_injection_ready' => true,
            'capability_status' => 'ready',
        ];

        return [
            'runtime ready' => ['runtime ready', $ready, true],
            'runtime not ready' => ['runtime not ready', array_replace($ready, ['audio_injection_ready' => false]), false],
            'reconnect' => ['reconnect', $ready + ['playback' => ['state' => 'idle']], true],
            'disconnect' => ['disconnect', array_replace($ready, ['state_connected' => false]), false],
            'heartbeat with full status' => ['heartbeat with full status', $ready + ['heartbeat' => ['sequence' => 42]], true],
            'agent restart partial heartbeat' => ['agent restart partial heartbeat', ['state_connected' => false, 'running' => true], false],
            'runtime restart status' => ['runtime restart status', $ready + ['uptime_sec' => 1], true],
            'bridge restart not connected' => ['bridge restart not connected', array_replace($ready, ['ts_server_connected' => false]), false],
            'empty queue' => ['empty queue', $ready + ['queue_count' => 0], true],
            'queue with playback' => ['queue with playback', $ready + ['queue' => ['items' => [['id' => 'q1']]], 'playback' => ['state' => 'playing']], true],
            'audio backend ready' => ['audio backend ready', $ready, true],
            'audio backend not ready' => ['audio backend not ready', array_replace($ready, ['audio_injection_ready' => false]), false],
            'database persist payload' => ['database persist payload', $ready + ['last_runtime_sync' => '2026-06-25T00:00:00+00:00'], true],
            'customer controller payload' => ['customer controller payload', $ready + ['panel' => 'customer'], true],
            'admin controller payload' => ['admin controller payload', $ready + ['panel' => 'admin'], true],
            'status normalizer rejects legacy-only ready' => ['status normalizer rejects legacy-only ready', ['playback_status' => ['audio_backend_ready' => true, 'audio_backend_status' => 'ready']], false],
        ];
    }

    public function testRuntimeReadyClearsStaleSocketError(): void
    {
        $payload = (new MusicbotRuntimeStatusNormalizer())->normalizePayload([
            'state_connected' => true,
            'ts_server_connected' => true,
            'voice_client_available' => true,
            'audio_injection_ready' => true,
            'capability_status' => 'ready',
            'last_error' => 'runtime control socket unavailable; command queued as state file',
        ]);

        self::assertArrayNotHasKey('last_error', $payload);
        self::assertArrayNotHasKey('active_last_error', $payload);
        self::assertSame('runtime control socket unavailable; command queued as state file', $payload['last_historical_error']);
        self::assertSame('TeamSpeak Client Backend ist bereit', $payload['diagnostic']);
    }

    public function testRuntimeNotReadyKeepsSocketErrorActive(): void
    {
        $payload = (new MusicbotRuntimeStatusNormalizer())->normalizePayload([
            'state_connected' => false,
            'ts_server_connected' => false,
            'voice_client_available' => false,
            'audio_injection_ready' => false,
            'capability_status' => 'client_backend_required',
            'last_error' => 'runtime control socket unavailable; command queued as state file',
        ]);

        self::assertSame('runtime control socket unavailable; command queued as state file', $payload['active_last_error']);
    }

    public function testPlaybackDecoderErrorKeepsRealErrorActiveWhenRuntimeReady(): void
    {
        $payload = (new MusicbotRuntimeStatusNormalizer())->normalizePayload([
            'state_connected' => true,
            'ts_server_connected' => true,
            'voice_client_available' => true,
            'audio_injection_ready' => true,
            'capability_status' => 'ready',
            'playback_status' => ['decoder_status' => 'error', 'last_error' => 'Decoder konnte Stream nicht öffnen'],
        ]);

        self::assertSame('Decoder konnte Stream nicht öffnen', $payload['active_last_error']);
        self::assertSame('Decoder konnte Stream nicht öffnen', $payload['playback_status']['active_last_error']);
    }

    public function testAdminAndCustomerControllersUseNormalizerForActiveLastError(): void
    {
        $admin = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Admin/AdminMusicbotController.php');
        $customer = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        self::assertIsString($admin);
        self::assertIsString($customer);

        self::assertStringContainsString('resolveActiveLastError($runtimePayload)', $admin);
        self::assertStringContainsString('resolveActiveLastError($payload, $normal)', $customer);
    }

    public function testQueueRepositoryDoesNotUseReservedInstanceAlias(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Repository/MusicbotQueueItemRepository.php');
        self::assertIsString($source);
        self::assertStringNotContainsString("'queueItem.instance', 'instance'", $source);
        self::assertStringContainsString("'queueItem.instance', 'musicbotInstance'", $source);
    }

    public function testPanelsPreferRuntimeReadyOverLegacyCapabilityFields(): void
    {
        foreach ([
            __DIR__.'/../../templates/admin/musicbot/show.html.twig',
            __DIR__.'/../../templates/customer/musicbot/show.html.twig',
        ] as $template) {
            $source = file_get_contents($template);
            self::assertIsString($source);
            self::assertStringContainsString('TeamSpeak Client Backend ist bereit', $source);
            self::assertStringContainsString('TeamSpeak Client Backend ist bereit', $source);
        }
    }
}
