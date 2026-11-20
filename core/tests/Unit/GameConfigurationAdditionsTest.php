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
use App\Module\Gameserver\Application\Configuration\GameConfigDocument;
use App\Module\Gameserver\Application\Configuration\GameConfigurationAdditions;
use App\Module\Gameserver\Application\Configuration\GameSetupProfiles;
use App\Module\Gameserver\Application\Configuration\StructuredGameConfiguration;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GameConfigurationAdditionsTest extends TestCase
{
    #[DataProvider('nativeFormats')]
    public function testNewValuesAreInsertedWithNativeTypesAndOtherDataSurvives(string $format, string $content, string $key, string $type, string $value, string $preserved): void
    {
        self::assertTrue(GameConfigurationAdditions::canAdd($content, $format, $key));
        $prepared = GameConfigurationAdditions::prepare($content, $format, [$key => $value], [['key' => $key, 'type' => $type]]);
        $saved = GameConfigDocument::patch($prepared, $format, [$key => $value]);
        self::assertSame($value, GameConfigDocument::values($saved, $format)[$key]);
        self::assertStringContainsString($preserved, $saved);
        if ('json' === $format) {
            $data = json_decode($saved, true, flags: JSON_THROW_ON_ERROR);
            if ('settings.rate' === $key) {
                self::assertSame(1.5, $data['settings']['rate']);
            }
        }
    }

    public static function nativeFormats(): iterable
    {
        yield 'Source CFG' => ['convar', "// keep\nhostname \"Server\"\nexec custom.cfg\n", 'mp_roundtime', 'decimal', '3.5', 'exec custom.cfg'];
        yield 'Java properties' => ['properties', "# keep\nmotd=Old\n", 'allow-flight', 'boolean', 'true', '# keep'];
        yield 'flat semicolon lists' => ['flat', "Mods=A;B\n", 'GlobalChat', 'boolean', 'false', 'Mods=A;B'];
        yield 'existing INI section' => ['ini', "[ServerSettings]\nName=Old\n[Other]\nKeep=1\n", 'ServerSettings.DayCycleSpeedScale', 'decimal', '1.5', '[Other]'];
        yield 'new INI section' => ['ini', "; keep\n[Other]\nKeep=1\n", '/Script/Engine.GameSession.MaxPlayers', 'integer', '12', '; keep'];
        yield 'JSON nested number' => ['json', '{"settings":{},"unknown":[],"empty":{}}', 'settings.rate', 'decimal', '1.5', '"empty": {}'];
        yield 'JSON bool' => ['json', '{"unknown":[1,2]}', 'enabled', 'boolean', 'false', '"unknown"'];
        yield 'YAML bool' => ['yaml', "unknown: keep\n", 'enabled', 'boolean', 'false', 'unknown: keep'];
        yield 'XML property' => ['xml', '<ServerSettings><!-- keep --><custom value="1"/></ServerSettings>', 'EnemySpawnMode', 'boolean', 'true', '<!-- keep -->'];
        yield 'Palworld member' => ['unreal', "[/Script/Pal.PalGameWorldSettings]\nOptionSettings=(ServerName=\"Old\",Nested=(X=1,Y=2))\n", '/Script/Pal.PalGameWorldSettings.OptionSettings.PalDamageRateAttack', 'decimal', '1.5', 'Nested=(X=1,Y=2)'];
        yield 'DayZ assignment' => ['assignment', "// keep\nhostname=\"Server\";\nclass Missions { class DayZ { template=\"keep\"; }; };\n", 'disable3rdPerson', 'integer', '1', 'template="keep"'];
        yield 'FiveM convar' => ['fivem', "ensure chat\n", 'sv_enforceGameBuild', 'integer', '3258', 'ensure chat'];
    }

    public function testListsAndExistingStructuredValuesCannotBeReplaced(): void
    {
        foreach (['{"settings":[1,2]}', '{"settings":null}', '{"settings":"keep"}'] as $content) {
            self::assertFalse(GameConfigurationAdditions::canAdd($content, 'json', 'settings.enabled'));
        }
        self::assertFalse(GameConfigurationAdditions::canAdd('{"settings":{}}', 'json', 'settings'));
        self::assertFalse(GameConfigurationAdditions::canAdd('[]', 'json', 'name'));
    }

    public function testOnlyDocumentedEditableMissingFieldsAreOffered(): void
    {
        $template = new Template('cs2', 'CS2', null, null, null, [], 'cs2.sh +map de_dust2', [], [], [], [], '', '', [], []);
        $template->setRequirementVars([['key' => 'SERVER_PASSWORD', 'scope' => 'admin_only']]);
        $instance = new Instance(new User('fields@example.test', UserType::Customer), $template, new Agent('fields-node', []), 1, 1024, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $configuration = new StructuredGameConfiguration(new ConfigSchemaRepository($registry), new GameDefinitionRepository($registry));
        $file = ['path' => 'game/csgo/cfg/server.cfg', 'label' => 'server.cfg', 'format' => 'convar', 'schema' => []];
        $fields = array_column($configuration->availableFields($instance, $file, "hostname \"Existing\"\n"), null, 'key');
        self::assertArrayHasKey('mp_roundtime', $fields);
        foreach (['hostname', 'sv_password', 'sv_maxplayers', 'ip', 'exec', 'sbox_maxprops', 'tf_bot_quota'] as $key) {
            self::assertArrayNotHasKey($key, $fields);
        }
        $this->expectException(\InvalidArgumentException::class);
        $configuration->validate(array_values($fields), ['exec' => 'bad.cfg']);
    }

    public function testContinuedPropertiesAreNotOfferedAsNewFields(): void
    {
        $template = new Template('minecraft_bedrock', 'Bedrock', null, null, null, [], '', [], [], [], [], '', '', [], []);
        $instance = new Instance(new User('fields@example.test', UserType::Customer), $template, new Agent('fields-node', []), 1, 1024, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $configuration = new StructuredGameConfiguration(new ConfigSchemaRepository($registry), new GameDefinitionRepository($registry));
        $fields = $configuration->availableFields($instance, ['path' => 'server.properties', 'label' => 'server.properties', 'format' => 'properties', 'schema' => []], "server-name=Old\\\n name\n");
        self::assertNotContains('server-name', array_column($fields, 'key'));
        $fields = $configuration->availableFields($instance, ['path' => 'server.properties', 'label' => 'server.properties', 'format' => 'properties', 'schema' => []], "SERVER-NAME=WrongCase\n");
        self::assertContains('server-name', array_column($fields, 'key'));
    }

    public function testSatisfactoryUsesRealIniPathsAndSections(): void
    {
        self::assertSame(['FactoryGame/Saved/Config/WindowsServer/Game.ini', 'FactoryGame/Saved/Config/WindowsServer/Engine.ini'], GameSetupProfiles::configurationPaths('satisfactory_windows', 'FactoryGame/Saved/Config/WindowsServer/ServerSettings.ini'));
        self::assertSame('ini', GameSetupProfiles::format('satisfactory', 'FactoryGame/Saved/Config/LinuxServer/Game.ini'));
        self::assertSame('/Script/Engine.GameSession.MaxPlayers', GameSetupProfiles::field('satisfactory', 'Game.ini', 'MaxPlayers')['write_key']);
    }

    public function testEveryAvailableCatalogSettingSurvivesItsNativeWriteFormat(): void
    {
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $configuration = new StructuredGameConfiguration(new ConfigSchemaRepository($registry), new GameDefinitionRepository($registry));
        $checked = 0;
        foreach ((new \App\Module\Core\Application\GameTemplateSeedCatalog())->listTemplates() as $entry) {
            $template = new Template($entry['game_key'], $entry['display_name'], null, null, null, [], $entry['start_params'], $entry['env_vars'], $entry['config_files'], [], [], '', '', [], []);
            $instance = new Instance(new User('matrix@example.test', UserType::Customer), $template, new Agent('matrix-node', []), 1, 1024, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
            $instance->setMaxSlots(20);
            $instance->setCurrentSlots(12);
            foreach ($entry['config_files'] as $config) {
                $format = GameSetupProfiles::format($entry['game_key'], $config['path']);
                $file = ['path' => $config['path'], 'label' => $config['path'], 'format' => $format, 'schema' => []];
                $content = match ($format) {
                    'json', 'jsonc', 'yaml', 'yml' => '{}',
                    'xml' => '<ServerSettings><!-- keep --></ServerSettings>',
                    'unreal' => "[/Script/Pal.PalGameWorldSettings]\nOptionSettings=(Existing=1)\n",
                    default => "# keep\n",
                };
                $available = $configuration->availableFields($instance, $file, $content);
                foreach ($available as $field) {
                    $value = $field['options'][0] ?? match ($field['type']) {
                        'integer' => (string) max(1, $field['min'] ?? 1),
                        'decimal' => (string) min($field['max'] ?? PHP_FLOAT_MAX, max(1.5, $field['min'] ?? 1.5)),
                        'boolean' => 'false',
                        default => 'Sample',
                    };
                    $changes = $configuration->validate($available, [$field['key'] => $value]);
                    $prepared = GameConfigurationAdditions::prepare($content, $format, $changes, $available);
                    try {
                        $saved = GameConfigDocument::patch($prepared, $format, $changes);
                    } catch (\InvalidArgumentException $exception) {
                        self::fail($entry['game_key'].': '.$field['key'].' - '.$exception->getMessage());
                    }
                    $read = GameConfigDocument::values($saved, $format);
                    self::assertArrayHasKey($field['key'], $read, $entry['game_key'].': '.$field['key']);
                    if ('decimal' === $field['type']) {
                        self::assertSame((float) $value * $field['divisor'], (float) $read[$field['key']], $entry['game_key'].': '.$field['key']);
                    } else {
                        self::assertSame(strtolower($value), strtolower($read[$field['key']]), $entry['game_key'].': '.$field['key']);
                    }
                    ++$checked;
                }
            }
        }
        self::assertGreaterThan(500, $checked);
    }
}
