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
use App\Module\Gameserver\Application\Configuration\JsonGameConfigDocument;
use App\Module\Gameserver\Application\Configuration\StructuredGameConfiguration;
use App\Module\Gameserver\Application\Configuration\VRisingGameConfiguration;
use App\Module\Gameserver\Application\MinecraftConfigurationPolicy;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VRisingGameConfigurationTest extends TestCase
{
    private const string PATH = 'save-data/Settings/ServerGameSettings.json';
    private const array FILE = ['path' => self::PATH, 'label' => 'Spielregeln', 'format' => 'jsonc', 'schema' => []];

    private function instance(string $game = 'v_rising'): Instance
    {
        $template = new Template($game, 'V Rising', null, null, null, [], '', [], [], [], [], '', '', [], []);
        $instance = new Instance(new User('rules@example.test', UserType::Customer), $template, new Agent('rules-node', []), 1, 1024, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
        $instance->setMaxSlots(20);
        $instance->setCurrentSlots(16);

        return $instance;
    }

    private function configuration(): StructuredGameConfiguration
    {
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);

        return new StructuredGameConfiguration(new ConfigSchemaRepository($registry), new GameDefinitionRepository($registry));
    }

    public function testDefaultPathsRegisterBothFilesWithoutRewritingCustomPathsOrOtherGames(): void
    {
        foreach (['v_rising', 'v_rising_windows'] as $game) {
            self::assertSame(['save-data/Settings/ServerHostSettings.json', self::PATH], GameSetupProfiles::configurationPaths($game, 'save-data/Settings/ServerHostSettings.json'));
            self::assertSame(['custom/ServerHostSettings.json'], GameSetupProfiles::configurationPaths($game, 'custom/ServerHostSettings.json'));
            self::assertTrue(VRisingGameConfiguration::canInitialize($this->instance($game), self::PATH));
            self::assertSame("{}\n", VRisingGameConfiguration::initialConfig($this->instance($game), self::PATH));
        }
        self::assertSame(['save-data/Settings/ServerHostSettings.json'], GameSetupProfiles::configurationPaths('factorio', 'save-data/Settings/ServerHostSettings.json'));
        self::assertFalse(VRisingGameConfiguration::canInitialize($this->instance('factorio'), self::PATH));
        self::assertFalse(VRisingGameConfiguration::canInitialize($this->instance(), 'ServerHostSettings.json'));
    }

    public function testDocumentedFieldsExistInTheNativeSteamDefaultsAndKeepTheirTypes(): void
    {
        // Steam app 1829350, depot 1829351, manifest 250813270647323596.
        $content = file_get_contents(__DIR__.'/../Fixtures/gameserver/vrising-native-server-game-settings.json');
        self::assertIsString($content);
        // Some repository copies normalize CRLF during import. Compare the
        // LF form while still hashing the BOM and every other byte.
        self::assertSame('4b24e9c37371bb8b357c3c687ea89e3d7d64b8b57d9bec360523f091082dfcf7', hash('sha256', str_replace("\r\n", "\n", $content)));
        $configuration = $this->configuration();
        $instance = $this->instance();
        $native = GameConfigDocument::values($content, 'jsonc');
        $definitions = GameSetupProfiles::file('v_rising', self::PATH)['fields'];
        self::assertGreaterThan(140, count($definitions));
        foreach ($definitions as $meta) {
            self::assertArrayHasKey($meta['write_key'], $native);
        }
        $fields = $configuration->fields($instance, self::FILE, $content);
        self::assertSame([], $configuration->availableFields($instance, self::FILE, $content));
        $changes = $configuration->validate($fields, ['GameModeType' => 'PvE', 'ClanSize' => '8', 'AllowGlobalChat' => 'false', 'TraderModifiers.PriceModifier' => '1.5', 'PlayerInteractionSettings.VSPlayerWeekdayTime.StartHour' => '0']);
        $saved = GameConfigDocument::patch($content, 'jsonc', $changes);
        MinecraftConfigurationPolicy::assertEditable($instance, self::PATH, $content, $saved, true);
        $data = JsonGameConfigDocument::decode($saved);
        self::assertSame('PvE', $data->GameModeType);
        self::assertSame(8, $data->ClanSize);
        self::assertFalse($data->AllowGlobalChat);
        self::assertSame(1.5, $data->TraderModifiers->PriceModifier);
        self::assertSame(0, $data->PlayerInteractionSettings->VSPlayerWeekdayTime->StartHour);
        self::assertSame([], $data->VBloodUnitSettings);
        self::assertSame([], $data->UnlockedAchievements);
        self::assertSame([], $data->UnlockedResearchs);
        self::assertSame(0, $data->WarEventGameSettings->Interval);
        self::assertStringStartsWith("\xEF\xBB\xBF", $saved);
    }

    #[DataProvider('enumRepresentations')]
    public function testEnumsKeepTheExistingNumericOrNamedRepresentation(string $content, string $key, string $input, mixed $expected, string $label): void
    {
        $configuration = $this->configuration();
        $fields = $configuration->fields($this->instance(), self::FILE, $content);
        $field = array_column($fields, null, 'key')[$key];
        self::assertSame($label, $field['option_labels'][$input]);
        self::assertContains($input, $field['options']);
        $saved = GameConfigDocument::patch($content, 'jsonc', $configuration->validate($fields, [$key => $input]));
        $values = GameConfigDocument::values($saved, 'jsonc');
        self::assertSame((string) $expected, $values[$key]);
        $data = JsonGameConfigDocument::decode($saved);
        foreach (explode('.', $key) as $part) {
            $data = $data->{$part};
        }
        self::assertSame($expected, $data);
    }

    public static function enumRepresentations(): iterable
    {
        yield 'numeric event interval' => ['{"WarEventGameSettings":{"Interval":0}}', 'WarEventGameSettings.Interval', '6', 6, '12 Stunden'];
        yield 'named event interval' => ['{"WarEventGameSettings":{"Interval":"Minimum"}}', 'WarEventGameSettings.Interval', 'Extensive', 'Extensive', '12 Stunden'];
        yield 'numeric major duration' => ['{"WarEventGameSettings":{"MajorDuration":1}}', 'WarEventGameSettings.MajorDuration', '7', 7, '2 Stunden'];
        yield 'named major duration' => ['{"WarEventGameSettings":{"MajorDuration":"VeryShort"}}', 'WarEventGameSettings.MajorDuration', 'Maximum', 'Maximum', '2 Stunden'];
        yield 'numeric minor duration' => ['{"WarEventGameSettings":{"MinorDuration":1}}', 'WarEventGameSettings.MinorDuration', '5', 5, '45 Minuten'];
        yield 'named minor duration' => ['{"WarEventGameSettings":{"MinorDuration":"Minimum"}}', 'WarEventGameSettings.MinorDuration', 'VeryLong', 'VeryLong', '45 Minuten'];
        yield 'named mode' => ['{"GameModeType":"PvP"}', 'GameModeType', 'PvE', 'PvE', 'PvE'];
        yield 'numeric mode' => ['{"GameModeType":1}', 'GameModeType', '0', 0, 'PvE'];
        yield 'named difficulty' => ['{"GameDifficulty":"Normal"}', 'GameDifficulty', 'Hard', 'Hard', 'Brutal'];
        yield 'numeric difficulty' => ['{"GameDifficulty":1}', 'GameDifficulty', '2', 2, 'Brutal'];
        yield 'numeric clan limit' => ['{"CastleStatModifiers_Global":{"CastleHeartLimitType":0}}', 'CastleStatModifiers_Global.CastleHeartLimitType', '1', 1, 'Je Clan'];
        yield 'named clan limit' => ['{"CastleStatModifiers_Global":{"CastleHeartLimitType":"User"}}', 'CastleStatModifiers_Global.CastleHeartLimitType', 'Clan', 'Clan', 'Je Clan'];
        yield 'unknown numeric current value' => ['{"GameModeType":99}', 'GameModeType', '1', 1, 'PvP'];
    }

    public function testAllNativeScalarsAreClassifiedAndOnlyActiveRulesAreOffered(): void
    {
        $content = file_get_contents(__DIR__.'/../Fixtures/gameserver/vrising-native-server-game-settings.json');
        self::assertIsString($content);
        $values = GameConfigDocument::values($content, 'jsonc');
        $definitions = GameSetupProfiles::file('v_rising', self::PATH)['fields'];
        $known = array_column($definitions, 'write_key');
        $native = array_keys($values);
        sort($known);
        sort($native);
        self::assertSame($native, $known);
        $configuration = $this->configuration();
        $fields = $configuration->fields($this->instance(), self::FILE, $content);
        self::assertCount(count($native) - 5, $fields);
        self::assertSame([], $configuration->availableFields($this->instance(), self::FILE, $content));
        $hidden = ['JournalVBloodSourceUnitMaxDistance', 'CastleMinimumDistanceInFloors', 'ResearchTimeModifier', 'VampireStatModifiers.SiegePowerModifier', 'EquipmentStatModifiers_Global.MovementSpeedModifier'];
        foreach ($hidden as $key) {
            self::assertNotContains($key, array_column($fields, 'key'));
        }
        $changes = $configuration->validate($fields, ['WarEventGameSettings.Interval' => '7', 'CastleStatModifiers_Global.RoutingStationLimit' => '255']);
        $saved = GameConfigDocument::patch($content, 'jsonc', $changes);
        $after = GameConfigDocument::values($saved, 'jsonc');
        foreach ($hidden as $key) {
            self::assertSame($values[$key], $after[$key]);
        }
        self::assertSame('7', $after['WarEventGameSettings.Interval']);
        self::assertSame('255', $after['CastleStatModifiers_Global.RoutingStationLimit']);
    }

    public function testNewEventRulesUseNativeNumericCodesAndTypedScaling(): void
    {
        $configuration = $this->configuration();
        $instance = $this->instance();
        $available = $configuration->availableFields($instance, self::FILE, '{}');
        $byKey = array_column($available, null, 'key');
        foreach (['Interval', 'MajorDuration', 'MinorDuration'] as $name) {
            $field = $byKey['WarEventGameSettings.'.$name];
            self::assertSame('integer', $field['type']);
            self::assertSame(['0', '1', '2', '3', '4', '5', '6', '7'], $field['options']);
            self::assertSame('Interval' === $name ? '30 Minuten' : '15 Minuten', $field['option_labels']['0']);
        }
        $changes = $configuration->validate($available, ['WarEventGameSettings.Interval' => '0', 'WarEventGameSettings.MajorDuration' => '7', 'WarEventGameSettings.MinorDuration' => '5', 'WarEventGameSettings.ScalingPlayers1.PointsModifier' => '0.01', 'WarEventGameSettings.ScalingPlayers4.DropModifier' => '100', 'CastleStatModifiers_Global.RoutingStationLimit' => '0']);
        $prepared = GameConfigurationAdditions::prepare('{}', 'jsonc', $changes, $available);
        $data = JsonGameConfigDocument::decode(GameConfigDocument::patch($prepared, 'jsonc', $changes));
        self::assertSame(0, $data->WarEventGameSettings->Interval);
        self::assertSame(7, $data->WarEventGameSettings->MajorDuration);
        self::assertSame(5, $data->WarEventGameSettings->MinorDuration);
        self::assertSame(0.01, $data->WarEventGameSettings->ScalingPlayers1->PointsModifier);
        self::assertSame(100.0, $data->WarEventGameSettings->ScalingPlayers4->DropModifier);
        self::assertSame(0, $data->CastleStatModifiers_Global->RoutingStationLimit);
        self::assertSame(16, $instance->getCurrentSlots());
        self::assertSame(20, $instance->getMaxSlots());
    }

    public function testExplicitOperatorSchemaCanExposeAVersionSpecificHiddenRule(): void
    {
        $configuration = $this->configuration();
        $file = self::FILE;
        $file['schema'] = ['ResearchTimeModifier' => ['hidden' => false, 'label' => 'Eigene Forschungsregel']];
        $fields = $configuration->fields($this->instance(), $file, '{"ResearchTimeModifier":1.0,"CustomRule":true}');
        self::assertSame(['ResearchTimeModifier', 'CustomRule'], array_column($fields, 'key'));
        self::assertSame('Eigene Forschungsregel', $fields[0]['label']);
        $changes = $configuration->validate($fields, ['ResearchTimeModifier' => '2.0']);
        self::assertSame(2.0, JsonGameConfigDocument::decode(GameConfigDocument::patch('{"ResearchTimeModifier":1.0}', 'jsonc', $changes))->ResearchTimeModifier);
    }

    public function testOperatorSchemaCanRestrictTheAvailableNativeEnumChoices(): void
    {
        $configuration = $this->configuration();
        $file = self::FILE;
        $file['schema'] = ['GameModeType' => ['label' => 'Festgelegter Spielmodus', 'options' => ['0']]];
        $fields = $configuration->fields($this->instance(), $file, '{"GameModeType":1}');
        self::assertSame('Festgelegter Spielmodus', $fields[0]['label']);
        self::assertSame(['0'], $fields[0]['options']);
        self::assertSame(['GameModeType' => '0'], $configuration->validate($fields, ['GameModeType' => '0']));
        $this->expectException(\InvalidArgumentException::class);
        $configuration->validate($fields, ['GameModeType' => '1']);
    }

    public function testAddingRulesUsesNamedEnumsAndDoesNotOverwriteComplexValues(): void
    {
        $configuration = $this->configuration();
        $instance = $this->instance();
        $content = '{"Custom":[{},null,[1,2]],"GameTimeModifiers":null}';
        $available = $configuration->availableFields($instance, self::FILE, $content);
        self::assertNotContains('GameTimeModifiers.DayStartHour', array_column($available, 'key'));
        $changes = $configuration->validate($available, ['GameModeType' => 'PvE', 'CastleStatModifiers_Global.HeartLimits.Level5.ServantLimit' => '12']);
        $prepared = GameConfigurationAdditions::prepare($content, 'jsonc', $changes, $available);
        $data = JsonGameConfigDocument::decode(GameConfigDocument::patch($prepared, 'jsonc', $changes));
        self::assertSame('PvE', $data->GameModeType);
        self::assertSame(12, $data->CastleStatModifiers_Global->HeartLimits->Level5->ServantLimit);
        self::assertInstanceOf(\stdClass::class, $data->Custom[0]);
        self::assertNull($data->GameTimeModifiers);
        self::assertNull($data->Custom[1]);
        self::assertSame([1, 2], $data->Custom[2]);
    }

    #[DataProvider('invalidValues')]
    public function testNativeBoundsAndEnumChoicesAreValidated(string $key, string $value): void
    {
        $configuration = $this->configuration();
        $fields = $configuration->availableFields($this->instance(), self::FILE, '{}');
        $this->expectException(\InvalidArgumentException::class);
        $configuration->validate($fields, [$key => $value]);
    }

    public static function invalidValues(): iterable
    {
        yield ['WarEventGameSettings.Interval', '-1'];
        yield ['WarEventGameSettings.Interval', '8'];
        yield ['WarEventGameSettings.Interval', 'Long'];
        yield ['WarEventGameSettings.MajorDuration', '8'];
        yield ['WarEventGameSettings.MinorDuration', '1.5'];
        yield ['WarEventGameSettings.ScalingPlayers1.PointsModifier', '0'];
        yield ['WarEventGameSettings.ScalingPlayers2.DropModifier', '100.01'];
        yield ['WarEventGameSettings.ScalingPlayers3.PointsModifier', 'NaN'];
        yield ['WarEventGameSettings.ScalingPlayers4.DropModifier', '1e309'];
        yield ['CastleStatModifiers_Global.RoutingStationLimit', '-1'];
        yield ['CastleStatModifiers_Global.RoutingStationLimit', '256'];
        yield ['CastleStatModifiers_Global.RoutingStationLimit', '1.5'];
        yield ['JournalVBloodSourceUnitMaxDistance', '20'];
        yield ['CastleMinimumDistanceInFloors', '3'];
        yield ['ResearchTimeModifier', '2'];
        yield ['VampireStatModifiers.SiegePowerModifier', '2'];
        yield ['EquipmentStatModifiers_Global.MovementSpeedModifier', '2'];
        yield ['ClanSize', '51'];
        yield ['ClanSize', '1.5'];
        yield ['GameDifficulty', 'Brutal']; // Native enum is Hard, UI label is Brutal.
        yield ['GameModeType', 'PvX'];
        yield ['PlayerInteractionSettings.VSPlayerWeekendTime.StartHour', '24'];
        yield ['WarEventGameSettings.WeekdayTime.StartMinute', '-1'];
        yield ['CastleStatModifiers_Global.HeartLimits.Level1.ServantLimit', '36'];
        yield ['InventoryStacksModifier', '0'];
        yield ['TraderModifiers.PriceModifier', '11'];
        yield ['VBloodUnitSettings', 'invalid scalar'];
    }

    #[DataProvider('invalidPairs')]
    public function testRangePairsCannotGuessImplicitPresetValues(string $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MinecraftConfigurationPolicy::assertEditable($this->instance(), self::PATH, '{}', $content, true);
    }

    public static function invalidPairs(): iterable
    {
        yield ['{"InactivityKillTimeMin":100}'];
        yield ['{"InactivityKillTimeMin":100,"InactivityKillTimeMax":99}'];
        yield ['{"GameTimeModifiers":{"BloodMoonFrequency_Max":20}}'];
        yield ['{"GameTimeModifiers":{"BloodMoonFrequency_Min":30,"BloodMoonFrequency_Max":20}}'];
    }

    public function testUnchangedLegacyRangesArePreservedAndExplicitValidRangesAreAccepted(): void
    {
        $instance = $this->instance();
        MinecraftConfigurationPolicy::assertEditable($instance, self::PATH, '{"InactivityKillTimeMin":100}', '{"InactivityKillTimeMin":100,"ClanSize":8}', true);
        MinecraftConfigurationPolicy::assertEditable($instance, self::PATH, '{}', '{"InactivityKillTimeMin":100,"InactivityKillTimeMax":200,"GameTimeModifiers":{"BloodMoonFrequency_Min":10,"BloodMoonFrequency_Max":20}}', true);
        self::assertSame(16, $instance->getCurrentSlots());
        self::assertSame(20, $instance->getMaxSlots());
    }
}
