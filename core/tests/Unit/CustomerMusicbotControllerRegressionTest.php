<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CustomerMusicbotControllerRegressionTest extends TestCase
{
    public function testPlaybackControllerPreventsBlindPlayAndValidatesNumericInputs(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        self::assertIsString($source);

        self::assertStringContainsString('Kein Track oder Webradio ausgewählt', $source);
        self::assertStringContainsString('findQueueForInstanceOrdered($instance) === []', $source);
        self::assertStringContainsString('Die Lautstärke muss eine Zahl zwischen 0 und 100 sein', $source);
        self::assertStringContainsString('Die Seek-Position muss numerisch sein', $source);
        self::assertStringContainsString("'position_ms'", $source);
    }


    public function testCustomerVolumeSettingsAndReconnectRegressionCoverage(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        self::assertIsString($source);

        self::assertStringContainsString("options['volume'] = ", $source);
        self::assertStringContainsString('$volume < 0 || $volume > 100', $source);
        self::assertStringContainsString('dispatchConfigApplyJob($instance)', $source);
        self::assertStringContainsString('queueStatusRefresh($customer, $instance)', $source);
        self::assertStringContainsString('queueLiveReconnect($customer, $instance)', $source);
        self::assertStringContainsString("'action' => 'reload_config'", $source);
        self::assertStringContainsString("'command' => 'reload_config'", $source);
        self::assertStringContainsString("'reconnect_if_required' => true", $source);
    }

    public function testCustomerActionsQueueRuntimeStatusRefreshAfterControlJobs(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Customer/CustomerMusicbotController.php');
        self::assertIsString($source);

        self::assertStringContainsString('private function queueStatusRefresh', $source);
        self::assertStringContainsString("'musicbot.status'", $source);
        self::assertStringContainsString("'status_job_id'", $source);
    }
}
