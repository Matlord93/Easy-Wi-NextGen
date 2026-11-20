<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\Configuration\GameSetupProfiles;
use App\Module\Gameserver\Application\Configuration\SourceServerSetup;
use PHPUnit\Framework\TestCase;

final class SourceServerSetupUnitTest extends TestCase
{
    private function instance(string $game, string $parameters): Instance
    {
        $template = new Template($game, 'Source server', null, null, null, [], $parameters, [], [], [], [], '', '', [], []);

        return new Instance(new User('source@example.test', UserType::Customer), $template, new Agent('source-node', []), 1, 1024, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
    }

    public function testLegacyCs2DropsTickrateAndKeepsOtherFlags(): void
    {
        $instance = $this->instance('cs2', '{{INSTANCE_DIR}}/game/cs2.sh -dedicated -tickrate {{TICKRATE}} -maxplayers 32 +map de_dust2 -condebug');
        $parameters = SourceServerSetup::parameters($instance);
        self::assertStringNotContainsString('-tickrate', $parameters);
        self::assertStringContainsString('-condebug', $parameters);
        self::assertStringContainsString('-maxplayers {{MAX_PLAYERS}}', $parameters);
        self::assertArrayNotHasKey('TICKRATE', SourceServerSetup::fields($instance->getTemplate()));
    }

    public function testLegacyCsgoGetsRealSlotArgumentAndDoesNotInventModeOverride(): void
    {
        $instance = $this->instance('csgo_legacy', '{{INSTANCE_DIR}}/srcds_run -game csgo -tickrate 128 +map de_dust2');
        self::assertSame('de_dust2', SourceServerSetup::defaults($instance)['MAP']);
        self::assertSame('128', SourceServerSetup::defaults($instance)['TICKRATE']);
        $parameters = SourceServerSetup::parameters($instance);
        self::assertStringContainsString('-maxplayers_override {{MAX_PLAYERS}}', $parameters);
        self::assertStringContainsString('-tickrate {{TICKRATE}}', $parameters);
        self::assertStringNotContainsString('+game_mode', $parameters);
        $instance->setSetupVars(['GAME_TYPE' => '0', 'GAME_MODE' => '1']);
        self::assertStringContainsString('+game_mode {{GAME_MODE}}', SourceServerSetup::parameters($instance));
    }

    public function testQuotedWindowsBinaryIsSupportedAndCustomWrapperIsUnchanged(): void
    {
        $instance = $this->instance('cs2_windows', '"C:/Game Servers/game/bin/win64/cs2.exe" -dedicated +map de_dust2');
        self::assertTrue(SourceServerSetup::supports($instance->getTemplate()));
        self::assertStringStartsWith('"C:/Game Servers/game/bin/win64/cs2.exe"', SourceServerSetup::parameters($instance));
        $instance->getTemplate()->setStartParams('custom-srcds_run --keep-this');
        self::assertFalse(SourceServerSetup::supports($instance->getTemplate()));
        self::assertSame('custom-srcds_run --keep-this', SourceServerSetup::parameters($instance));
        self::assertSame([], SourceServerSetup::fields($instance->getTemplate()));
    }

    public function testModeServerCfgUsesTheCorrectSourceProfile(): void
    {
        self::assertSame('convar', GameSetupProfiles::format('cs2', 'game/csgo/cfg/gamemode_competitive_server.cfg'));
        self::assertSame('convar', GameSetupProfiles::format('csgo_legacy', 'csgo/cfg/gamemode_casual_server.cfg'));
        self::assertNotEmpty(GameSetupProfiles::field('cs2', 'game/csgo/cfg/gamemode_competitive_server.cfg', 'mp_friendlyfire'));
        self::assertNull(GameSetupProfiles::format('valheim', 'gamemode_competitive_server.cfg'));
    }

    public function testInitialConfigPreservesExplicitPasswordsAndEscapesConvarValues(): void
    {
        $instance = $this->instance('css', '{{INSTANCE_DIR}}/srcds_run -game cstrike +map de_dust2');
        $instance->getTemplate()->setEnvVars([['key' => 'RCON_PASSWORD', 'value' => 'change-me']]);
        self::assertStringContainsString('rcon_password ""', SourceServerSetup::initialConfig($instance));
        $instance->setSetupVars(['RCON_PASSWORD' => 'change-me', 'SERVER_PASSWORD' => 'a"b\\c']);
        $content = SourceServerSetup::initialConfig($instance);
        $values = \App\Module\Gameserver\Application\Configuration\GameConfigDocument::values($content, 'convar');
        self::assertSame('change-me', $values['rcon_password']);
        self::assertSame('a"b\\c', $values['sv_password']);
        $instance->setSetupVars(['SERVER_PASSWORD' => "unsafe\nquit"]);
        $this->expectException(\InvalidArgumentException::class);
        SourceServerSetup::initialConfig($instance);
    }

    public function testSourceStartupExecutesItsConfigOnceAndPreservesExplicitBindAddresses(): void
    {
        foreach (['cs2', 'csgo_legacy', 'css', 'tf2', 'hl2dm', 'l4d', 'l4d2', 'dods', 'garrys_mod'] as $game) {
            $instance = $this->instance($game, ('cs2' === $game ? 'cs2.sh' : 'srcds_run').' +map test');
            $parameters = SourceServerSetup::parameters($instance);
            self::assertStringContainsString('+ip 0.0.0.0', $parameters);
            self::assertSame(1, substr_count($parameters, '+exec server.cfg'));
            self::assertStringNotContainsString('-authkey', $parameters);
            self::assertStringNotContainsString('+host_workshop_map', $parameters);
            $instance->getTemplate()->setStartParams($parameters.' -ip 192.0.2.25');
            self::assertStringContainsString('-ip 192.0.2.25', SourceServerSetup::parameters($instance));
            self::assertSame(1, substr_count(SourceServerSetup::parameters($instance), '+exec server.cfg'));
        }
    }

    public function testCounterStrikeUsesTheNativeWorkshopMapCommandAndLoginOrder(): void
    {
        foreach (['cs2', 'cs2_windows', 'csgo_legacy', 'csgo_legacy_windows'] as $game) {
            $source2 = str_starts_with($game, 'cs2');
            $instance = $this->instance($game, ($source2 ? 'cs2.sh' : 'srcds_run').' +map de_dust2 +sv_setsteamaccount {{STEAM_GSLT}} -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}');
            $instance->setSetupVars(['STEAM_GSLT' => str_repeat('a', 32), 'AUTHKEY' => str_repeat('b', 32), 'WORKSHOP_MAP' => '123456']);
            $parameters = SourceServerSetup::parameters($instance);
            foreach (['+sv_setsteamaccount {{STEAM_GSLT}}', '-authkey {{AUTHKEY}}', '+host_workshop_map {{WORKSHOP_MAP}}'] as $argument) {
                self::assertStringContainsString($argument, $parameters);
            }
            self::assertLessThan(strpos($parameters, '+host_workshop_map'), strpos($parameters, '+sv_setsteamaccount'));
            if ($source2) {
                self::assertLessThan(strpos($parameters, '+host_workshop_map'), strpos($parameters, '+map'));
                self::assertLessThan(strpos($parameters, '+map'), strpos($parameters, '+sv_setsteamaccount'));
            } else {
                self::assertStringNotContainsString('+map ', $parameters);
            }
            $instance->setSetupVars(['STEAM_GSLT' => str_repeat('a', 32), 'AUTHKEY' => str_repeat('b', 32)]);
            $parameters = SourceServerSetup::parameters($instance);
            self::assertStringNotContainsString('-authkey', $parameters);
            self::assertStringContainsString('+map {{MAP}}', $parameters);
            $instance->setSetupVars(['WORKSHOP_COLLECTION' => '123456', 'WORKSHOP_MAP' => '234567']);
            $parameters = SourceServerSetup::parameters($instance);
            $mapArgument = $source2 ? '+host_workshop_map' : '+workshop_start_map';
            self::assertStringContainsString('+host_workshop_collection {{WORKSHOP_COLLECTION}}', $parameters);
            self::assertStringContainsString($mapArgument.' {{WORKSHOP_MAP}}', $parameters);
            self::assertStringNotContainsString($source2 ? '+workshop_start_map' : '+host_workshop_map', $parameters);
            self::assertLessThan(strpos($parameters, $mapArgument), strpos($parameters, '+host_workshop_collection'));
        }
    }

    public function testGmodUsesWorkshopCollectionsAndDoesNotInheritCounterStrikeFlags(): void
    {
        $instance = $this->instance('garrys_mod', 'srcds_run -game garrysmod +map gm_construct -authkey {{AUTHKEY}} +host_workshop_map {{WORKSHOP_MAP}}');
        $instance->setSetupVars(['STEAM_GSLT' => str_repeat('c', 32), 'WORKSHOP_COLLECTION' => '123456', 'GAMEMODE' => 'sandbox']);
        $parameters = SourceServerSetup::parameters($instance);
        self::assertStringContainsString('+host_workshop_collection {{WORKSHOP_COLLECTION}}', $parameters);
        self::assertStringContainsString('+gamemode {{GAMEMODE}}', $parameters);
        self::assertStringContainsString('+sv_setsteamaccount {{STEAM_GSLT}}', $parameters);
        self::assertStringNotContainsString('-authkey', $parameters);
        self::assertStringNotContainsString('+host_workshop_map', $parameters);
        self::assertArrayNotHasKey('AUTHKEY', SourceServerSetup::fields($instance->getTemplate()));
        self::assertArrayNotHasKey('WORKSHOP_MAP', SourceServerSetup::fields($instance->getTemplate()));
    }

    public function testAnExistingOperatorSteamLoginIsPreservedOnOtherSourceBranches(): void
    {
        $instance = $this->instance('l4d2', 'srcds_run -game left4dead2 +sv_setsteamaccount {{STEAM_GSLT}} +map c1m1_hotel');
        $instance->setGslKey(str_repeat('d', 32));
        self::assertArrayHasKey('STEAM_GSLT', SourceServerSetup::fields($instance->getTemplate()));
        self::assertSame(str_repeat('d', 32), SourceServerSetup::defaults($instance)['STEAM_GSLT']);
        self::assertStringContainsString('+sv_setsteamaccount {{STEAM_GSLT}}', SourceServerSetup::parameters($instance));
    }
}
