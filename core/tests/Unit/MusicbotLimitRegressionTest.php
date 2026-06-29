<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\MusicbotPlanLimitResolver;
use PHPUnit\Framework\TestCase;

final class MusicbotLimitRegressionTest extends TestCase
{
    public function testMusicbotLimitDefaultAndAdminApiMappingExist(): void
    {
        $defaults = MusicbotPlanLimitResolver::planDefaults();
        self::assertArrayHasKey('max_musicbots', $defaults);
        self::assertArrayHasKey('max_playlist_items', $defaults);
        self::assertArrayHasKey('allow_stream', $defaults);
        self::assertArrayHasKey('allow_api', $defaults);
        self::assertSame(1, $defaults['max_musicbots']);

        $apiController = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Api/MusicbotApiController.php');
        self::assertIsString($apiController);
        self::assertStringContainsString("'max_musicbots' => 'setMaxMusicbots'", $apiController);
        self::assertStringContainsString("'max_playlist_items' => 'setMaxPlaylistItems'", $apiController);
        self::assertStringContainsString("'stream_allowed' => 'setAllowStream'", $apiController);
        self::assertStringContainsString("'api_allowed' => 'setAllowApi'", $apiController);
    }

    public function testQuotaServiceBlocksCreationAtMusicbotLimit(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Application/MusicbotQuotaService.php');
        self::assertIsString($source);
        self::assertStringContainsString('assertCanCreateMusicbot', $source);
        self::assertStringContainsString('$count >= $limits->maxMusicbots', $source);
        self::assertStringContainsString('Musicbot limit reached', $source);
        self::assertStringContainsString('assertCanAddPlaylistItem', $source);
        self::assertStringContainsString('assertApiAllowed', $source);
        self::assertStringContainsString('assertStreamAllowed', $source);
    }

    public function testAdminLimitsUiAndMigrationCoverRequestedFields(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/admin/musicbot/limits.html.twig');
        self::assertIsString($template);
        foreach (['max_musicbots', 'max_tracks', 'max_storage_mb', 'max_upload_size_mb', 'max_queue_items', 'max_playlists', 'max_playlist_items', 'web_radio_allowed', 'youtube_allowed', 'teamspeak_commands_allowed', 'playlists_allowed', 'autodj_allowed', 'plugins_allowed', 'discord_allowed', 'stream_allowed', 'api_allowed'] as $field) {
            self::assertStringContainsString($field, $template);
        }

        $migration = file_get_contents(__DIR__.'/../../../Migrations.php');
        self::assertIsString($migration);
        self::assertStringContainsString('max_playlist_items', $migration);
        self::assertStringContainsString('allow_stream', $migration);
        self::assertStringContainsString('allow_api', $migration);
    }
}
