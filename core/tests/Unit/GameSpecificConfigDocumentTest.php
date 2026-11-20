<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\GameTemplateSeedCatalog;
use App\Module\Gameserver\Application\Configuration\GameConfigDocument;
use App\Module\Gameserver\Application\Configuration\GameSetupProfiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GameSpecificConfigDocumentTest extends TestCase
{
    public function testEveryCatalogTemplateHasAnExplicitSetupProfileAndFileFormat(): void
    {
        $templates = (new GameTemplateSeedCatalog())->listTemplates();
        self::assertGreaterThanOrEqual(54, count($templates));
        foreach ($templates as $template) {
            $game = $template['game_key'];
            $profile = GameSetupProfiles::summary($game);
            self::assertNotSame($game, $profile['title'], $game);
            self::assertNotEmpty($profile['description']);
            if ([] === $template['config_files']) {
                self::assertTrue($profile['startup_only'], $game);
            }
            foreach ($template['config_files'] as $file) {
                self::assertNotNull(GameSetupProfiles::format($game, $file['path']), $game.': '.$file['path']);
            }
        }
        self::assertSame([], GameSetupProfiles::field('unknown_game', 'server.cfg', 'hostname'));
        self::assertSame([], GameSetupProfiles::field('palworld', 'server.cfg', 'hostname'));
    }

    #[DataProvider('formats')]
    public function testNativeFormatsSaveIndividualSettingsAndKeepOtherData(string $format, string $content, array $changes, array $expected, array $preserved): void
    {
        $saved = GameConfigDocument::patch($content, $format, $changes);
        $values = GameConfigDocument::values($saved, $format);
        foreach ($expected as $key => $value) {
            self::assertSame($value, $values[$key], $key);
        }
        foreach ($preserved as $text) {
            self::assertStringContainsString($text, $saved);
        }
    }

    public static function formats(): iterable
    {
        yield 'Palworld tuple' => ['unreal', "; keep\r\n[/Script/Pal.PalGameWorldSettings]\r\nOptionSettings=(ServerName=\"Old, world\",ExpRate=1.000000,bEnableFriendlyFire=False,DeathPenalty=All,PublicPort=8211,Nested=(X=1,Y=2))\r\n", ['/Script/Pal.PalGameWorldSettings.OptionSettings.ServerName' => 'New, "world"', '/Script/Pal.PalGameWorldSettings.OptionSettings.ExpRate' => '1.25', '/Script/Pal.PalGameWorldSettings.OptionSettings.bEnableFriendlyFire' => 'true'], ['/Script/Pal.PalGameWorldSettings.OptionSettings.ServerName' => 'New, "world"', '/Script/Pal.PalGameWorldSettings.OptionSettings.ExpRate' => '1.25', '/Script/Pal.PalGameWorldSettings.OptionSettings.bEnableFriendlyFire' => 'True'], ["; keep\r\n", 'PublicPort=8211', 'Nested=(X=1,Y=2)', 'DeathPenalty=All']];
        yield '7 Days XML' => ['xml', '<?xml version="1.0"?><ServerSettings><!-- keep --><property name="ServerName" value="Old"/><property name="ServerMaxPlayerCount" value="20"/><property name="ServerPort" value="26900"/><custom value="untouched"/></ServerSettings>', ['ServerName' => 'Name & "title"', 'ServerMaxPlayerCount' => '12'], ['ServerName' => 'Name & "title"', 'ServerMaxPlayerCount' => '12', 'ServerPort' => '26900'], ['<!-- keep -->', '<custom value="untouched"/>']];
        yield 'DayZ assignments' => ['assignment', "// keep\nhostname = \"Old\"; // title\nmaxPlayers=20;\nclass Missions {\n class DayZ { template=\"dayzOffline.chernarusplus\"; };\n};\n", ['hostname' => 'New', 'maxPlayers' => '12'], ['hostname' => 'New', 'maxPlayers' => '12'], ['// title', 'template="dayzOffline.chernarusplus";', 'class Missions']];
        yield 'Arma assignments' => ['assignment', "/* comment */\nhostname=\"Old\";\nmaxPlayers=20;\npassword=\"\";\n", ['hostname' => 'New "quoted"', 'password' => 'secret'], ['hostname' => 'New "quoted"', 'password' => 'secret', 'maxPlayers' => '20'], ['/* comment */']];
        yield 'Terraria flat' => ['flat', "# keep\nworld=worlds/main.wld\nmaxplayers=20\npassword=old\nport=7777\n", ['maxplayers' => '12', 'password' => 'my#secret;value'], ['maxplayers' => '12', 'password' => 'my#secret;value', 'world' => 'worlds/main.wld'], ['# keep', 'port=7777']];
        yield 'Zomboid lists' => ['flat', "Mods=ModA;ModB\nWorkshopItems=123;456\nPVP=true\n", ['Mods' => 'ModA;ModC'], ['Mods' => 'ModA;ModC', 'WorkshopItems' => '123;456', 'PVP' => 'true'], ['WorkshopItems=123;456']];
        yield 'FiveM directives' => ['fivem', "endpoint_add_tcp \"0.0.0.0:30120\"\nsets sv_projectName \"Old\"\nsetr locale \"de-DE\"\nsv_maxclients 20\nensure chat\n", ['sv_projectName' => 'New project', 'sv_maxclients' => '12'], ['sv_projectName' => 'New project', 'sv_maxclients' => '12', 'locale' => 'de-DE'], ['endpoint_add_tcp "0.0.0.0:30120"', 'sets sv_projectName', 'ensure chat']];
        yield 'Unreal INI numbers' => ['ini', "[ServerSettings]\nXPMultiplier=1.000000\nServerPVE=False\nName=\"Old\" ; keep\n", ['ServerSettings.XPMultiplier' => '1.25', 'ServerSettings.ServerPVE' => 'true'], ['ServerSettings.XPMultiplier' => '1.25', 'ServerSettings.ServerPVE' => 'true'], ['XPMultiplier=1.25', '; keep']];
    }

    public function testLegacyStartArgumentsUseTheCurrentPlayerLimit(): void
    {
        self::assertSame('RustDedicated +server.maxplayers 12 +server.seed 42', GameSetupProfiles::startParams('rust_windows', 'RustDedicated +server.maxplayers 50 +server.seed 42', 12));
        self::assertSame('ShooterGameServer TheIsland?MaxPlayers=12?listen -log', GameSetupProfiles::startParams('ark', 'ShooterGameServer TheIsland?MaxPlayers=70?listen -log', 12));
        self::assertSame('custom-wrapper --limit 50', GameSetupProfiles::startParams('custom', 'custom-wrapper --limit 50', 12));
    }

    public function testXmlDtdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GameConfigDocument::values('<!DOCTYPE ServerSettings [<!ENTITY secret SYSTEM "file:///etc/passwd">]><ServerSettings><property name="ServerName" value="&secret;"/></ServerSettings>', 'xml');
    }

    public function testXmlWritesRejectIllegalCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GameConfigDocument::patch('<ServerSettings><property name="ServerName" value="Old"/></ServerSettings>', 'xml', ['ServerName' => "bad\x01name"]);
    }

    public function testUnsupportedCommandsAndNestedClassesAreNotExposed(): void
    {
        self::assertArrayNotHasKey('ensure', GameConfigDocument::values("ensure chat\nsv_hostname \"Server\"", 'fivem'));
        self::assertArrayNotHasKey('template', GameConfigDocument::values("hostname=\"Server\";\nclass Missions {\ntemplate=\"keep\";\n};", 'assignment'));
    }
}
