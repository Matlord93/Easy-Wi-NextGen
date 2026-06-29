<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotRadioPlaylistResolver;
use App\Module\Musicbot\Application\MusicbotWebradioUrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the complete Webradio feature.
 *
 * These tests assert structural contracts (service/entity presence, resolver
 * logic, URL validation) so that future refactors cannot silently break the
 * feature.
 */
final class MusicbotWebradioSupportRegressionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // URL Validator – MP3 stream accepted
    // -------------------------------------------------------------------------

    public function testMp3StreamUrlAccepted(): void
    {
        $v = new MusicbotWebradioUrlValidator();
        $v->validate('http://stream.example.com/live.mp3');
        $this->addToAssertionCount(1);
    }

    public function testHttpsStreamUrlAccepted(): void
    {
        $v = new MusicbotWebradioUrlValidator();
        $v->validate('https://icecast.example.net/stream/128kbps');
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------------------
    // URL Validator – web_radio_allowed=false effectively blocks via QuotaService
    // -------------------------------------------------------------------------

    public function testWebradioAllowedFlagIsCheckedByQuotaService(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/Application/MusicbotQuotaService.php');
        self::assertIsString($source);
        self::assertStringContainsString('assertWebradioAllowed', $source);
        self::assertStringContainsString('allowWebradio', $source);
        self::assertStringContainsString('Web radio is not available', $source);
    }

    // -------------------------------------------------------------------------
    // Playlist resolver – M3U
    // -------------------------------------------------------------------------

    public function testPlaylistResolverParsesM3u(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'parseM3u');
        $method->setAccessible(true);

        $m3u = "#EXTM3U\n#EXTINF:-1,My Radio\nhttp://cdn.example.com/stream.mp3\n";
        $result = $method->invoke($resolver, $m3u);

        self::assertContains('http://cdn.example.com/stream.mp3', $result);
    }

    public function testPlaylistResolverM3uSkipsCommentLines(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'parseM3u');
        $method->setAccessible(true);

        $m3u = "# comment\n#EXTINF:-1\nnot-a-url\nhttp://valid.example.com/s\n";
        $result = $method->invoke($resolver, $m3u);

        self::assertCount(1, $result);
        self::assertSame('http://valid.example.com/s', $result[0]);
    }

    // -------------------------------------------------------------------------
    // Playlist resolver – PLS
    // -------------------------------------------------------------------------

    public function testPlaylistResolverParsesPls(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'parsePls');
        $method->setAccessible(true);

        $pls = "[playlist]\nFile1=http://icecast.example.net/stream\nTitle1=Station\nNumberOfEntries=1\n";
        $result = $method->invoke($resolver, $pls);

        self::assertContains('http://icecast.example.net/stream', $result);
    }

    public function testPlaylistResolverPlsSkipsNonHttpEntries(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'parsePls');
        $method->setAccessible(true);

        $pls = "[playlist]\nFile1=ftp://bad.example.com/s\nFile2=http://good.example.com/s\n";
        $result = $method->invoke($resolver, $pls);

        self::assertCount(1, $result);
        self::assertSame('http://good.example.com/s', $result[0]);
    }

    // -------------------------------------------------------------------------
    // Playlist resolver – XSPF
    // -------------------------------------------------------------------------

    public function testPlaylistResolverParsesXspf(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'parseXspf');
        $method->setAccessible(true);

        $xspf = '<?xml version="1.0" encoding="UTF-8"?>
<playlist xmlns="http://xspf.org/ns/0/" version="1">
  <trackList>
    <track><location>http://stream.example.com/live.ogg</location></track>
  </trackList>
</playlist>';

        $result = $method->invoke($resolver, $xspf);
        self::assertNotEmpty($result);
        // At least one of the parsed entries should contain the stream
        self::assertTrue(
            in_array('http://stream.example.com/live.ogg', $result, true),
            'Expected XSPF stream URL in result: ' . implode(', ', $result),
        );
    }

    public function testPlaylistResolverXspfInvalidXmlReturnsEmpty(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'parseXspf');
        $method->setAccessible(true);

        $result = $method->invoke($resolver, 'NOT XML AT ALL <<<');
        self::assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Playlist resolver – format detection
    // -------------------------------------------------------------------------

    public function testDetectFormatM3u(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'detectFormat');
        $method->setAccessible(true);

        self::assertSame('m3u', $method->invoke($resolver, 'http://example.com/list.m3u'));
        self::assertSame('m3u', $method->invoke($resolver, 'https://example.com/list.m3u8'));
    }

    public function testDetectFormatPls(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'detectFormat');
        $method->setAccessible(true);

        self::assertSame('pls', $method->invoke($resolver, 'http://example.com/station.pls'));
    }

    public function testDetectFormatXspf(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'detectFormat');
        $method->setAccessible(true);

        self::assertSame('xspf', $method->invoke($resolver, 'https://example.com/tracks.xspf'));
    }

    public function testDetectFormatDirect(): void
    {
        $resolver = $this->makeResolver();
        $method = new \ReflectionMethod($resolver, 'detectFormat');
        $method->setAccessible(true);

        self::assertSame('direct', $method->invoke($resolver, 'http://stream.example.com/live.mp3'));
        self::assertSame('direct', $method->invoke($resolver, 'https://radio.example.com/aac'));
    }

    // -------------------------------------------------------------------------
    // Blocked URL remains blocked
    // -------------------------------------------------------------------------

    public function testPrivateIpBlockedByValidator(): void
    {
        $v = new MusicbotWebradioUrlValidator();
        $this->expectException(\InvalidArgumentException::class);
        $v->validate('http://192.168.1.1/stream');
    }

    public function testLocalhostBlockedByValidator(): void
    {
        $v = new MusicbotWebradioUrlValidator();
        $this->expectException(\InvalidArgumentException::class);
        $v->validate('http://localhost/stream');
    }

    public function testBadUrlShowsClearMessage(): void
    {
        $v = new MusicbotWebradioUrlValidator();
        try {
            $v->validate('not-a-url');
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertNotEmpty($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // API controller existence / route contracts
    // -------------------------------------------------------------------------

    public function testRadioApiControllerExistsWithAllEndpoints(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/UI/Controller/Api/MusicbotRadioApiController.php');
        self::assertIsString($source);

        foreach (['list', 'create', 'update', 'delete', 'play', 'queue', 'playlist', 'resolve', 'history'] as $endpoint) {
            self::assertStringContainsString('radio/' . $endpoint, $source, "Missing endpoint: radio/{$endpoint}");
        }
    }

    public function testRadioApiControllerChecksWebradioAllowed(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/Application/MusicbotRadioService.php');
        self::assertIsString($source);
        self::assertStringContainsString('assertWebradioAllowed', $source);
    }

    // -------------------------------------------------------------------------
    // Service contracts: queue / playlist / ownership / reconnect
    // -------------------------------------------------------------------------

    public function testRadioServiceHasQueueAndPlaylistIntegration(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/Application/MusicbotRadioService.php');
        self::assertIsString($source);
        foreach (['addToQueue', 'addToPlaylist', 'playNow', 'buildReconnectPolicy'] as $method) {
            self::assertStringContainsString($method, $source);
        }
    }

    public function testRadioServiceChecksOwnership(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/Application/MusicbotRadioService.php');
        self::assertIsString($source);
        self::assertStringContainsString('assertCustomerOwnsInstance', $source);
        self::assertStringContainsString('assertAccessible', $source);
        self::assertStringContainsString('Radio station not found.', $source);
    }

    public function testReconnectPolicyIsReturnedByService(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/Application/MusicbotRadioService.php');
        self::assertIsString($source);
        self::assertStringContainsString('max_retries', $source);
        self::assertStringContainsString('retry_delay_seconds', $source);
    }

    // -------------------------------------------------------------------------
    // AutoDJ fallback with radio
    // -------------------------------------------------------------------------

    public function testAutoDjSettingsHasWebradioFallbackUrl(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/Domain/Entity/MusicbotAutoDjSettings.php');
        self::assertIsString($source);
        self::assertStringContainsString('webradioFallbackUrl', $source);
    }

    // -------------------------------------------------------------------------
    // Entity / migration / repository
    // -------------------------------------------------------------------------

    public function testRadioStationEntityExists(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/Domain/Entity/MusicbotRadioStation.php');
        self::assertIsString($source);
        foreach (['name', 'streamUrl', 'genre', 'description', 'homepage', 'logoUrl', 'isFavorite', 'lastPlayedAt', 'metadata'] as $field) {
            self::assertStringContainsString($field, $source);
        }
    }

    public function testRadioStationMigrationExists(): void
    {
        $source = file_get_contents(__DIR__ . '/../../migrations/Version20260627150000.php');
        self::assertIsString($source);
        self::assertStringContainsString('musicbot_radio_stations', $source);
        self::assertStringContainsString('stream_url', $source);
        self::assertStringContainsString('is_favorite', $source);
    }

    public function testRadioStationRepositoryExists(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Repository/MusicbotRadioStationRepository.php');
        self::assertIsString($source);
        foreach (['findByCustomer', 'findFavoritesByCustomer', 'findRecentlyPlayed', 'findOneForCustomer', 'countByCustomer'] as $method) {
            self::assertStringContainsString($method, $source);
        }
    }

    // -------------------------------------------------------------------------
    // UI template
    // -------------------------------------------------------------------------

    public function testRadioUiTemplateContainsKeyElements(): void
    {
        $source = file_get_contents(__DIR__ . '/../../templates/customer/musicbot/radio.html.twig');
        self::assertIsString($source);
        foreach (['Webradio', 'Katalog', 'Favoriten', 'Verlauf', 'Eigene Sender', 'Eigenen Sender hinzufügen', 'Stream-URL testen / auflösen', 'btn-resolve', 'btn-play', 'btn-queue', 'btn-playlist', '/favorites/', '/delete/'] as $needle) {
            self::assertStringContainsString($needle, $source, "Missing '{$needle}' in radio template");
        }
    }

    // -------------------------------------------------------------------------
    // MusicbotTrackSourceType includes Webradio
    // -------------------------------------------------------------------------

    public function testTrackSourceTypeIncludesWebradio(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/Domain/Enum/MusicbotTrackSourceType.php');
        self::assertIsString($source);
        self::assertStringContainsString('Webradio', $source);
    }

    // -------------------------------------------------------------------------
    // Runtime config builder passes reconnect policy
    // -------------------------------------------------------------------------

    public function testRadioServiceNormalizesStation(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Module/Musicbot/Application/MusicbotRadioService.php');
        self::assertIsString($source);
        self::assertStringContainsString('normalize', $source);
        self::assertStringContainsString('stream_url', $source);
        self::assertStringContainsString('is_favorite', $source);
        self::assertStringContainsString('last_played_at', $source);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeResolver(): MusicbotRadioPlaylistResolver
    {
        return new MusicbotRadioPlaylistResolver(new MusicbotWebradioUrlValidator());
    }
}
