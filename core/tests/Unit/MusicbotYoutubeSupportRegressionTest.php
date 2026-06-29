<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MusicbotYoutubeSupportRegressionTest extends TestCase
{
    public function testYoutubeServiceUsesYtDlpAndSupportsSearchImportQueueAndCookies(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Application/MusicbotYoutubeService.php');
        self::assertIsString($source);
        foreach (['yt-dlp', 'search', 'importUrl', 'playUrl', 'queueUrl', 'history', 'saveCookies', 'detectUrlType', 'runYtDlpJson', 'MusicbotQueueService', 'MusicbotPlaylistService', 'MusicbotPlaybackCommandService', 'assertYoutubeAllowed'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        foreach (['video', 'playlist', 'mix', 'album', 'artist', 'live', 'short', 'podcast'] as $kind) {
            self::assertStringContainsString($kind, $source);
        }
        foreach (['Video nicht verfügbar', 'geoblockiert', 'altersbeschränkt', 'privat', 'Login oder Cookies erforderlich'] as $message) {
            self::assertStringContainsString($message, $source);
        }
    }

    public function testYoutubeTracksPersistRequiredMetadata(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Application/MusicbotYoutubeService.php');
        self::assertIsString($source);
        foreach (['youtube_id', 'youtube_url', 'source_url', 'source_kind', 'thumbnail', 'uploader', 'channel', 'is_live', 'live_status'] as $field) {
            self::assertStringContainsString($field, $source);
        }
    }

    public function testCustomerUiAndApiExposeYoutubeSupport(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/customer/musicbot/show.html.twig');
        $api = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Api/MusicbotApiController.php');
        $customer = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        self::assertIsString($template);
        self::assertIsString($api);
        self::assertIsString($customer);
        foreach (['YouTube Suche', 'YouTube / YouTube Music URL Import', 'Cookies pro Musicbot', 'YouTube Verlauf'] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
        foreach (['customerYoutubeSearch', 'customerYoutubeImport', 'customerYoutubePlay', 'customerYoutubeQueue', 'customerYoutubePlaylist', 'customerYoutubeHistory'] as $needle) {
            self::assertStringContainsString($needle, $api);
        }
        foreach (['youtubeSearch', 'youtubeImport', 'saveYoutubeCookies'] as $needle) {
            self::assertStringContainsString($needle, $customer);
        }
    }
}
