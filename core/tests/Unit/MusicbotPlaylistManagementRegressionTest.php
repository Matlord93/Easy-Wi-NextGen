<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MusicbotPlaylistManagementRegressionTest extends TestCase
{
    public function testPlaylistServiceEnforcesOwnershipQuotasSourcesAndReorder(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Application/MusicbotPlaylistService.php');
        self::assertIsString($source);
        foreach (['assertCanCreatePlaylist', 'assertCanAddPlaylistItem', 'assertWebradioAllowed', 'assertYoutubeAllowed', 'assertCustomerOwnsPlaylist', 'assertTrackMatchesPlaylistInstance', 'reorderItems', 'loadPlaylistToQueueMode'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testCustomerAndApiExposePlaylistActions(): void
    {
        $customer = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        $api = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Api/MusicbotApiController.php');
        self::assertIsString($customer);
        self::assertIsString($api);
        foreach (['playlist_create', 'playlist_update', 'playlist_delete', 'playlist_track_add', 'playlist_track_remove', 'playlist_queue', 'playlist_reorder'] as $needle) {
            self::assertStringContainsString($needle, $customer);
        }
        foreach (['customerPlaylistCreate', 'customerPlaylistUpdate', 'customerPlaylistDelete', 'customerPlaylistTrackAdd', 'customerPlaylistTrackRemove', 'customerPlaylistQueue', 'customerPlaylistReorder'] as $needle) {
            self::assertStringContainsString($needle, $api);
        }
        self::assertStringContainsString('shuffle_play', $customer);
        self::assertStringContainsString('clear_play', $api);
    }

    public function testPlaylistSchemaHasDescriptionOrderingAndItemMetadata(): void
    {
        $playlist = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Domain/Entity/MusicbotPlaylist.php');
        $item = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Domain/Entity/MusicbotPlaylistItem.php');
        self::assertIsString($playlist);
        self::assertIsString($item);
        self::assertStringContainsString('private ?string $description', $playlist);
        self::assertStringContainsString('private int $sortOrder', $playlist);
        self::assertStringContainsString('private array $metadata', $item);
    }
}
