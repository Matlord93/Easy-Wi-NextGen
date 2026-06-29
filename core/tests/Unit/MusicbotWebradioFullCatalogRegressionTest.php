<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotRadioCatalogService;
use App\Module\Musicbot\Application\MusicbotRadioPlaylistResolver;
use App\Module\Musicbot\Application\MusicbotRadioService;
use App\Module\Musicbot\Application\MusicbotWebradioUrlValidator;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioFavorite;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioHistory;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Module\Core\Domain\Entity\User;
use App\Repository\MusicbotRadioFavoriteRepository;
use App\Repository\MusicbotRadioHistoryRepository;
use App\Repository\MusicbotRadioStationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the Webradio catalog, favorites, history and playback.
 */
final class MusicbotWebradioFullCatalogRegressionTest extends TestCase
{
    // ── Entity existence ───────────────────────────────────────────────────────

    public function testMusicbotRadioFavoriteEntityExists(): void
    {
        $this->assertTrue(class_exists(MusicbotRadioFavorite::class));
    }

    public function testMusicbotRadioHistoryEntityExists(): void
    {
        $this->assertTrue(class_exists(MusicbotRadioHistory::class));
    }

    public function testMusicbotRadioCatalogServiceExists(): void
    {
        $this->assertTrue(class_exists(MusicbotRadioCatalogService::class));
    }

    // ── MusicbotRadioStation – global flag & nullable customer ─────────────────

    public function testGlobalStationHasNoCustomer(): void
    {
        $station = new MusicbotRadioStation(null, 'Global FM', 'https://stream.test/live.mp3', true);
        $this->assertNull($station->getCustomer());
        $this->assertTrue($station->isGlobal());
    }

    public function testPrivateStationHasCustomer(): void
    {
        $user = $this->createMock(User::class);
        $station = new MusicbotRadioStation($user, 'My Radio', 'https://stream.test/live.mp3', false);
        $this->assertSame($user, $station->getCustomer());
        $this->assertFalse($station->isGlobal());
    }

    // ── MusicbotRadioStation – new catalog fields ─────────────────────────────

    public function testStationCountryField(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $station->setCountry('DE');
        $this->assertSame('DE', $station->getCountry());
    }

    public function testStationLanguageField(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $station->setLanguage('de');
        $this->assertSame('de', $station->getLanguage());
    }

    public function testStationTagsField(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $station->setTags(['rock', 'live', 'hd']);
        $this->assertSame(['rock', 'live', 'hd'], $station->getTags());
    }

    public function testStationTagsDeduplicated(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $station->setTags(['rock', 'rock', 'live']);
        $this->assertCount(2, $station->getTags());
    }

    public function testStationBitrateField(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $station->setBitrate(128);
        $this->assertSame(128, $station->getBitrate());
    }

    public function testStationFormatField(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $station->setFormat('mp3');
        $this->assertSame('mp3', $station->getFormat());
    }

    public function testStationIsActiveDefaultTrue(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $this->assertTrue($station->isActive());
    }

    public function testStationResolvedStreamUrl(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.m3u', true);
        $station->setResolvedStreamUrl('https://stream.test/live.mp3');
        $this->assertSame('https://stream.test/live.mp3', $station->getResolvedStreamUrl());
    }

    // ── MusicbotRadioFavorite entity ───────────────────────────────────────────

    public function testFavoriteEntityConstructor(): void
    {
        $user = $this->createMock(User::class);
        $station = new MusicbotRadioStation($user, 'Test FM', 'https://stream.test/live.mp3', false);
        $fav = new MusicbotRadioFavorite($user, $station);
        $this->assertSame($user, $fav->getCustomer());
        $this->assertSame($station, $fav->getStation());
        $this->assertInstanceOf(\DateTimeImmutable::class, $fav->getCreatedAt());
    }

    // ── MusicbotRadioHistory entity ────────────────────────────────────────────

    public function testHistoryEntityConstructor(): void
    {
        $user = $this->createMock(User::class);
        $station = new MusicbotRadioStation($user, 'Test FM', 'https://stream.test/live.mp3', false);
        $history = new MusicbotRadioHistory($user, $station);
        $this->assertSame($user, $history->getCustomer());
        $this->assertSame($station, $history->getStation());
        $this->assertNull($history->getInstance());
        $this->assertInstanceOf(\DateTimeImmutable::class, $history->getPlayedAt());
    }

    public function testHistoryEntityWithInstance(): void
    {
        $user = $this->createMock(User::class);
        $station = new MusicbotRadioStation($user, 'Test FM', 'https://stream.test/live.mp3', false);
        $instance = $this->createMock(MusicbotInstance::class);
        $history = new MusicbotRadioHistory($user, $station, $instance);
        $this->assertSame($instance, $history->getInstance());
    }

    // ── URL validation ─────────────────────────────────────────────────────────

    public function testValidMp3StreamAccepted(): void
    {
        $v = new MusicbotWebradioUrlValidator();
        $v->validate('https://stream.example.com/live.mp3');
        $this->addToAssertionCount(1);
    }

    public function testValidM3uUrlAccepted(): void
    {
        $v = new MusicbotWebradioUrlValidator();
        $v->validate('https://example.com/playlist.m3u');
        $this->addToAssertionCount(1);
    }

    public function testInvalidStreamUrlRejectedPrivateIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MusicbotWebradioUrlValidator())->validate('http://192.168.1.1/stream.mp3');
    }

    public function testInvalidStreamUrlRejectedLocalhost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MusicbotWebradioUrlValidator())->validate('http://localhost/stream.mp3');
    }

    public function testInvalidStreamUrlRejectedFileProtocol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MusicbotWebradioUrlValidator())->validate('file:///etc/passwd');
    }

    // ── Playlist resolver – M3U parsing ───────────────────────────────────────

    public function testM3uPlaylistResolved(): void
    {
        $resolver = new MusicbotRadioPlaylistResolver();
        $m3u = "#EXTM3U\n#EXTINF:-1,Test FM\nhttps://stream.test/live.mp3\n";
        $result = $this->callResolveBody($resolver, $m3u, 'audio/x-mpegurl');
        $this->assertSame('https://stream.test/live.mp3', $result);
    }

    public function testPlsPlaylistResolved(): void
    {
        $resolver = new MusicbotRadioPlaylistResolver();
        $pls = "[playlist]\nFile1=https://stream.test/live.mp3\nTitle1=Test FM\nLength1=-1\nNumberOfEntries=1\n";
        $result = $this->callResolveBody($resolver, $pls, 'audio/x-scpls');
        $this->assertSame('https://stream.test/live.mp3', $result);
    }

    public function testXspfPlaylistResolved(): void
    {
        $resolver = new MusicbotRadioPlaylistResolver();
        $xspf = '<?xml version="1.0"?><playlist xmlns="http://xspf.org/ns/0/"><trackList><track><location>https://stream.test/live.mp3</location></track></trackList></playlist>';
        $result = $this->callResolveBody($resolver, $xspf, 'application/xspf+xml');
        $this->assertSame('https://stream.test/live.mp3', $result);
    }

    // ── MusicbotRadioCatalogService – normalize ────────────────────────────────

    public function testNormalizeIncludesAllCatalogFields(): void
    {
        $station = new MusicbotRadioStation(null, 'Rock Antenne', 'https://stream.rockantenne.de/rockantenne/stream/mp3', true);
        $station->setGenre('Rock');
        $station->setCountry('DE');
        $station->setLanguage('de');
        $station->setBitrate(128);
        $station->setFormat('mp3');
        $station->setTags(['rock', 'classic-rock']);

        $catalogService = $this->buildCatalogService();
        $normalized = $catalogService->normalize($station, []);

        $this->assertArrayHasKey('id', $normalized);
        $this->assertArrayHasKey('name', $normalized);
        $this->assertArrayHasKey('stream_url', $normalized);
        $this->assertArrayHasKey('resolved_stream_url', $normalized);
        $this->assertArrayHasKey('genre', $normalized);
        $this->assertArrayHasKey('country', $normalized);
        $this->assertArrayHasKey('language', $normalized);
        $this->assertArrayHasKey('tags', $normalized);
        $this->assertArrayHasKey('bitrate', $normalized);
        $this->assertArrayHasKey('format', $normalized);
        $this->assertArrayHasKey('is_global', $normalized);
        $this->assertArrayHasKey('is_active', $normalized);
        $this->assertArrayHasKey('is_favorite', $normalized);
        $this->assertArrayHasKey('last_played_at', $normalized);
        $this->assertArrayHasKey('last_checked_at', $normalized);
        $this->assertArrayHasKey('metadata', $normalized);
        $this->assertArrayHasKey('customer_id', $normalized);
        $this->assertArrayHasKey('created_at', $normalized);
        $this->assertArrayHasKey('updated_at', $normalized);

        $this->assertSame('Rock Antenne', $normalized['name']);
        $this->assertTrue($normalized['is_global']);
        $this->assertFalse($normalized['is_favorite']);
        $this->assertSame('DE', $normalized['country']);
        $this->assertSame(128, $normalized['bitrate']);
    }

    public function testNormalizeMarksFavorite(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $catalogService = $this->buildCatalogService();

        $reflId = new \ReflectionProperty($station, 'id');
        $reflId->setAccessible(true);
        $reflId->setValue($station, 42);

        $normalized = $catalogService->normalize($station, [42]);
        $this->assertTrue($normalized['is_favorite']);
    }

    // ── MusicbotRadioService – findAccessible ──────────────────────────────────

    public function testFindAccessibleReturnsNullForMissingStation(): void
    {
        $stationRepo = $this->createMock(MusicbotRadioStationRepository::class);
        $stationRepo->method('find')->willReturn(null);
        $service = $this->buildRadioService(stationRepo: $stationRepo);
        $customer = $this->createMock(User::class);
        $this->assertNull($service->findAccessible(999, $customer));
    }

    public function testFindAccessibleReturnsGlobalStation(): void
    {
        $station = new MusicbotRadioStation(null, 'Global FM', 'https://stream.test/live.mp3', true);
        $stationRepo = $this->createMock(MusicbotRadioStationRepository::class);
        $stationRepo->method('find')->willReturn($station);
        $service = $this->buildRadioService(stationRepo: $stationRepo);
        $customer = $this->createMock(User::class);
        $this->assertSame($station, $service->findAccessible(1, $customer));
    }

    public function testFindAccessibleRejectsOtherCustomerPrivateStation(): void
    {
        $owner = $this->createMock(User::class);
        $owner->method('getId')->willReturn(1);
        $station = new MusicbotRadioStation($owner, 'Private FM', 'https://stream.test/live.mp3', false);

        $stationRepo = $this->createMock(MusicbotRadioStationRepository::class);
        $stationRepo->method('find')->willReturn($station);
        $service = $this->buildRadioService(stationRepo: $stationRepo);

        $other = $this->createMock(User::class);
        $other->method('getId')->willReturn(2);
        $this->assertNull($service->findAccessible(1, $other));
    }

    // ── MusicbotRadioService – buildReconnectPolicy ────────────────────────────

    public function testReconnectPolicyDefaults(): void
    {
        $service = $this->buildRadioService();
        $policy = $service->buildReconnectPolicy();
        $this->assertArrayHasKey('max_retries', $policy);
        $this->assertArrayHasKey('retry_delay_seconds', $policy);
        $this->assertArrayHasKey('backoff_multiplier', $policy);
        $this->assertGreaterThan(0, $policy['max_retries']);
        $this->assertGreaterThanOrEqual(1, $policy['retry_delay_seconds']);
        $this->assertSame(1.5, $policy['backoff_multiplier']);
    }

    // ── Repository class existence ─────────────────────────────────────────────

    public function testMusicbotRadioFavoriteRepositoryExists(): void
    {
        $this->assertTrue(class_exists(MusicbotRadioFavoriteRepository::class));
    }

    public function testMusicbotRadioHistoryRepositoryExists(): void
    {
        $this->assertTrue(class_exists(MusicbotRadioHistoryRepository::class));
    }

    // ── API controller existence ───────────────────────────────────────────────

    public function testRadioApiControllerExists(): void
    {
        $this->assertTrue(class_exists(\App\Module\Musicbot\UI\Controller\Api\MusicbotRadioApiController::class));
    }

    // ── Catalog service – promoteToGlobal ──────────────────────────────────────

    public function testPromoteToGlobalSetsFlags(): void
    {
        $user = $this->createMock(User::class);
        $station = new MusicbotRadioStation($user, 'My FM', 'https://stream.test/live.mp3', false);
        $this->assertFalse($station->isGlobal());

        $catalogService = $this->buildCatalogService();
        $catalogService->promoteToGlobal($station);

        $this->assertTrue($station->isGlobal());
        $this->assertTrue($station->isActive());
    }

    // ── Catalog service – markInactive / markActive ────────────────────────────

    public function testMarkInactiveSetsActiveToFalse(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $this->assertTrue($station->isActive());

        $catalogService = $this->buildCatalogService();
        $catalogService->markInactive($station);

        $this->assertFalse($station->isActive());
        $this->assertNotNull($station->getLastCheckedAt());
    }

    public function testMarkActiveSetsActiveToTrue(): void
    {
        $station = new MusicbotRadioStation(null, 'Test FM', 'https://stream.test/live.mp3', true);
        $station->setActive(false);

        $catalogService = $this->buildCatalogService();
        $catalogService->markActive($station);

        $this->assertTrue($station->isActive());
    }

    // ── Migration existence ────────────────────────────────────────────────────

    public function testMigrationForCatalogFieldsExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../migrations/Version20260627160000.php');
    }

    public function testMigrationForFavoritesExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../migrations/Version20260627170000.php');
    }

    public function testMigrationForHistoryExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../migrations/Version20260627180000.php');
    }

    public function testMigrationForSeedDataExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../migrations/Version20260627190000.php');
    }

    // ── Template existence ─────────────────────────────────────────────────────

    public function testRadioTemplateHasCatalogSection(): void
    {
        $template = file_get_contents(__DIR__ . '/../../templates/customer/musicbot/radio.html.twig');
        $this->assertStringContainsString('catalog', $template);
        $this->assertStringContainsString('Favoriten', $template);
        $this->assertStringContainsString('Verlauf', $template);
        $this->assertStringContainsString('Eigene Sender', $template);
        $this->assertStringContainsString('Play', $template);
        $this->assertStringContainsString('Queue', $template);
        $this->assertStringContainsString('Playlist', $template);
    }

    public function testRadioTemplateHasSearchControls(): void
    {
        $template = file_get_contents(__DIR__ . '/../../templates/customer/musicbot/radio.html.twig');
        $this->assertStringContainsString('catalog-query', $template);
        $this->assertStringContainsString('catalog-genre', $template);
        $this->assertStringContainsString('catalog-country', $template);
    }

    public function testRadioTemplateHasResolverSection(): void
    {
        $template = file_get_contents(__DIR__ . '/../../templates/customer/musicbot/radio.html.twig');
        $this->assertStringContainsString('resolve', $template);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Use reflection to call the private parseXxx methods on the resolver.
     */
    private function callResolveBody(MusicbotRadioPlaylistResolver $resolver, string $body, string $contentType): string
    {
        $format = match (true) {
            str_contains($contentType, 'scpls'), str_contains($body, '[playlist]') => 'pls',
            str_contains($contentType, 'xspf'), str_contains($body, '<playlist') => 'xspf',
            default => 'm3u',
        };
        $method = match ($format) {
            'pls' => 'parsePls',
            'xspf' => 'parseXspf',
            default => 'parseM3u',
        };
        $ref = new \ReflectionMethod($resolver, $method);
        $ref->setAccessible(true);
        return $ref->invoke($resolver, $body);
    }

    private function buildCatalogService(): MusicbotRadioCatalogService
    {
        $stationRepo = $this->createMock(MusicbotRadioStationRepository::class);
        $favoriteRepo = $this->createMock(MusicbotRadioFavoriteRepository::class);
        $historyRepo = $this->createMock(MusicbotRadioHistoryRepository::class);
        $quotaService = $this->createMock(\App\Module\Musicbot\Application\MusicbotQuotaService::class);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);

        return new MusicbotRadioCatalogService($stationRepo, $favoriteRepo, $historyRepo, $quotaService, $em);
    }

    private function buildRadioService(?MusicbotRadioStationRepository $stationRepo = null): MusicbotRadioService
    {
        $stationRepo ??= $this->createMock(MusicbotRadioStationRepository::class);
        $favoriteRepo = $this->createMock(MusicbotRadioFavoriteRepository::class);
        $queueRepo = $this->createMock(\App\Repository\MusicbotQueueItemRepository::class);
        $playlistRepo = $this->createMock(\App\Repository\MusicbotPlaylistRepository::class);
        $playlistItemRepo = $this->createMock(\App\Repository\MusicbotPlaylistItemRepository::class);
        $trackRepo = $this->createMock(\App\Repository\MusicbotTrackRepository::class);
        $resolver = $this->createMock(MusicbotRadioPlaylistResolver::class);
        $urlValidator = $this->createMock(MusicbotWebradioUrlValidator::class);
        $quotaService = $this->createMock(\App\Module\Musicbot\Application\MusicbotQuotaService::class);
        $catalogService = $this->createMock(MusicbotRadioCatalogService::class);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);

        return new MusicbotRadioService(
            $stationRepo, $favoriteRepo, $queueRepo, $playlistRepo, $playlistItemRepo,
            $trackRepo, $resolver, $urlValidator, $quotaService, $catalogService, $em,
        );
    }
}
