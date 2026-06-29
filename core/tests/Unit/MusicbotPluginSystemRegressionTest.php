<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Musicbot\Application\PluginRegistryService;
use PHPUnit\Framework\TestCase;

final class MusicbotPluginSystemRegressionTest extends TestCase
{
    public function testRegistryDefinesFirstPartyPluginsEventsAndActions(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Application/PluginRegistryService.php');
        self::assertIsString($source);
        foreach (['easywi.welcome_song', 'Welcome Song', 'easywi.idle_playlist', 'Idle Playlist', 'supportedEvents', 'supportedActions', 'enabled_by_default', 'first_party'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        foreach (['bot_started', 'user_joined_channel', 'queue_empty', 'chat_command_received', 'scheduled_tick'] as $event) {
            self::assertStringContainsString($event, $source);
        }
        foreach (['play_track', 'play_playlist', 'play_radio', 'play_youtube', 'queue_track', 'clear_queue', 'set_volume', 'send_chat_message', 'reconnect', 'trigger_autodj'] as $action) {
            self::assertStringContainsString($action, $source);
        }
    }

    public function testPluginActionsUseExistingSafeServices(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Application/MusicbotPluginActionService.php');
        self::assertIsString($source);
        foreach (['MusicbotQueueService', 'MusicbotPlaylistService', 'MusicbotPlaybackCommandService', 'MusicbotAutoDjService', 'assertWebradioAllowed', 'assertYoutubeAllowed'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringNotContainsString('shell_exec', $source);
        self::assertStringNotContainsString('proc_open', $source);
    }

    public function testPluginEventsAndLogsAreInstanceScoped(): void
    {
        $eventSource = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Application/MusicbotPluginEventService.php');
        $logEntity = file_get_contents(__DIR__.'/../../src/Module/Musicbot/Domain/Entity/MusicbotPluginLog.php');
        self::assertIsString($eventSource);
        self::assertIsString($logEntity);
        foreach (['findBy([\'instance\' => $instance, \'enabled\' => true])', 'handleWelcomeSong', 'handleIdlePlaylist', 'cooldownAllowed', 'dailyLimitAllowed'] as $needle) {
            self::assertStringContainsString($needle, $eventSource);
        }
        foreach (['private MusicbotInstance $instance', 'private User $customer', 'private string $pluginId', 'private string $event'] as $needle) {
            self::assertStringContainsString($needle, $logEntity);
        }
    }

    public function testCustomerAndApiExposePluginUiLogsAndEvents(): void
    {
        $template = file_get_contents(__DIR__.'/../../templates/customer/musicbot/show.html.twig');
        $api = file_get_contents(__DIR__.'/../../src/Module/Musicbot/UI/Controller/Api/MusicbotApiController.php');
        self::assertIsString($template);
        self::assertIsString($api);
        foreach (['panel-plugins', 'Verfügbare First-Party-Plugins', 'Plugin-Logs', 'customer_musicbot_plugin_assign'] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
        foreach (['customerPluginLogs', 'customerPluginEvent', 'pluginEventService', 'pluginLogService'] as $needle) {
            self::assertStringContainsString($needle, $api);
        }
    }
}
