<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MusicbotAutoDjFullSupportRegressionTest extends TestCase
{
    public function testAutoDjServiceCoversTriggersLimitsAndRepeatProtection(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Application/MusicbotAutoDjService.php');
        self::assertIsString($source);
        foreach (['assertAutoDjAllowed', 'assertPlaylistsAllowed', 'getMinQueueSize', 'playbackAllowsAutoDj', 'isInsideTimeWindow', 'filterRepeatProtection', 'avoidSameArtist', 'assertYoutubeAllowed', 'assertWebradioAllowed', 'addTrackToQueue'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testAutoDjSettingsSchemaContainsFullCustomerOptions(): void
    {
        $entity = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Domain/Entity/MusicbotAutoDjSettings.php');
        self::assertIsString($entity);
        foreach (['shuffle', 'repeat', 'idleSeconds', 'volumeOverride', 'timeWindowStart', 'timeWindowEnd', 'webradioFallbackUrl', 'allowYoutube', 'allowUploads', 'repeatProtectionWindow', 'avoidSameArtist', 'playlistIds'] as $needle) {
            self::assertStringContainsString($needle, $entity);
        }
    }

    public function testCustomerUiAndApiExposeAutoDjManagement(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/customer/musicbot/show.html.twig');
        $customer = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        $api = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Api/MusicbotApiController.php');
        self::assertIsString($template);
        self::assertIsString($customer);
        self::assertIsString($api);
        foreach (['AutoDJ Einstellungen', 'AutoDJ jetzt testen', 'Mindest-Queue', 'Idle-Zeit', 'Webradio-Fallback', 'Wiederholschutz'] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
        foreach (['saveAutoDj', 'triggerAutoDj', 'disableAutoDj'] as $needle) {
            self::assertStringContainsString($needle, $customer);
        }
        foreach (['customerAutoDjShow', 'customerAutoDjSave', 'customerAutoDjDisable', 'customerAutoDjTrigger'] as $needle) {
            self::assertStringContainsString($needle, $api);
        }
    }
}
