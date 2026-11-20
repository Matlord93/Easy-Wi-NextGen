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
use App\Module\Gameserver\Application\Configuration\JsonGameConfigDocument;
use App\Module\Gameserver\Application\Configuration\VRisingProgressionConfiguration as Progression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VRisingProgressionConfigurationTest extends TestCase
{
    private const string PATH = 'save-data/Settings/ServerGameSettings.json';
    private const int WOLF = -1905691330;
    private const int TIER1 = -495424062;
    private const int TIER2 = -1292809886;

    private function instance(string $game = 'v_rising'): Instance
    {
        return new Instance(new User('lists@example.test', UserType::Customer), new Template($game, 'V Rising', null, null, null, [], '', [], [], [], [], '', '', [], []), new Agent('lists-node', []), 1, 1024, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
    }

    public function testNativeEmptyListsOfferTheDocumentedCatalogWithoutAddingOverrides(): void
    {
        $content = file_get_contents(__DIR__.'/../Fixtures/gameserver/vrising-native-server-game-settings.json');
        $groups = array_column(Progression::describe($this->instance(), self::PATH, $content), null, 'key');
        foreach (['VBloodUnitSettings' => 64, 'UnlockedAchievements' => 30, 'UnlockedResearchs' => 3] as $key => $count) {
            self::assertTrue($groups[$key]['editable']);
            self::assertCount($count, $groups[$key]['items']);
            self::assertCount($count, array_unique(array_column($groups[$key]['items'], 'id')));
            self::assertSame([false], array_values(array_unique(array_column($groups[$key]['items'], 'selected'))));
        }
        self::assertSame($content, Progression::patch($this->instance(), self::PATH, $content, []));
        self::assertTrue(Progression::ownsField($this->instance(), self::PATH, 'VBloodUnitSettings.0.UnitId'));
        self::assertFalse(Progression::ownsField($this->instance(), self::PATH, 'UnitStatModifiers_VBlood.PowerModifier'));
        self::assertFalse(Progression::ownsField($this->instance('factorio'), self::PATH, 'UnlockedResearchs.0'));
        self::assertSame([], Progression::describe($this->instance(), 'ServerHostSettings.json', '{}'));
    }

    public function testEntryChangesPreserveUnknownIdsPropertiesOrderTypesAndOtherSettings(): void
    {
        $content = "\xEF\xBB\xBF".'{"Name":"keep","Custom":{"empty":{},"list":[null,1]},"VBloodUnitSettings":[{"UnitId":-1905691330,"UnitLevel":20,"DefaultUnlocked":false,"ModData":{"Keep":[1,2]}},{"UnitId":123,"UnitLevel":3,"DefaultUnlocked":true,"Other":null}],"UnlockedResearchs":[123,-495424062],"UnlockedAchievements":[456]}';
        $changes = ['VBloodUnitSettings' => ['upsert' => [['UnitId' => self::WOLF, 'UnitLevel' => 255, 'DefaultUnlocked' => true], ['UnitId' => 123, 'UnitLevel' => 0, 'DefaultUnlocked' => false]]], 'UnlockedResearchs' => ['add' => [self::TIER2], 'remove' => [self::TIER1]], 'UnlockedAchievements' => ['add' => [-1770927128]]];
        $saved = Progression::patch($this->instance(), self::PATH, $content, $changes);
        self::assertStringStartsWith("\xEF\xBB\xBF", $saved);
        $data = JsonGameConfigDocument::decode($saved);
        self::assertSame('keep', $data->Name);
        self::assertInstanceOf(\stdClass::class, $data->Custom->empty);
        self::assertSame([null, 1], $data->Custom->list);
        self::assertSame(self::WOLF, $data->VBloodUnitSettings[0]->UnitId);
        self::assertSame(255, $data->VBloodUnitSettings[0]->UnitLevel);
        self::assertTrue($data->VBloodUnitSettings[0]->DefaultUnlocked);
        self::assertSame([1, 2], $data->VBloodUnitSettings[0]->ModData->Keep);
        self::assertSame(123, $data->VBloodUnitSettings[1]->UnitId);
        self::assertNull($data->VBloodUnitSettings[1]->Other);
        self::assertSame(0, $data->VBloodUnitSettings[1]->UnitLevel);
        self::assertSame([123, self::TIER2], $data->UnlockedResearchs);
        self::assertSame([456, -1770927128], $data->UnlockedAchievements);
        $groups = array_column(Progression::describe($this->instance(), self::PATH, $saved), null, 'key');
        self::assertSame('Unbekannte Kennung (123)', array_column($groups['VBloodUnitSettings']['items'], null, 'id')[123]['label']);
    }

    public function testRemovingOverridesDoesNotTouchOtherListsOrUnknownData(): void
    {
        $content = '{"VBloodUnitSettings":[{"UnitId":123,"UnitLevel":10,"DefaultUnlocked":true}],"UnlockedAchievements":[999],"UnlockedResearchs":[-495424062],"World":"unchanged"}';
        $saved = Progression::patch($this->instance(), self::PATH, $content, ['VBloodUnitSettings' => ['remove' => [123]], 'UnlockedAchievements' => ['remove' => [999]]]);
        $data = JsonGameConfigDocument::decode($saved);
        self::assertSame([], $data->VBloodUnitSettings);
        self::assertSame([], $data->UnlockedAchievements);
        self::assertSame([self::TIER1], $data->UnlockedResearchs);
        self::assertSame('unchanged', $data->World);
    }

    public function testMissingListsCanBeAddedAndCaseVariantsDoNotCreateShadowProperties(): void
    {
        $content = '{"unlockedresearchs":[123]}';
        $saved = Progression::patch($this->instance(), self::PATH, $content, ['UnlockedResearchs' => ['add' => [self::TIER1]], 'VBloodUnitSettings' => ['upsert' => [['UnitId' => self::WOLF, 'UnitLevel' => 0, 'DefaultUnlocked' => false]]]]);
        $data = JsonGameConfigDocument::decode($saved);
        self::assertSame([123, self::TIER1], $data->unlockedresearchs);
        self::assertFalse(property_exists($data, 'UnlockedResearchs'));
        self::assertSame(0, $data->VBloodUnitSettings[0]->UnitLevel);
    }

    public function testBossCaseVariantsAreUpdatedWithoutCreatingShadowProperties(): void
    {
        $content = '{"VBloodUnitSettings":[{"unitid":123,"unitlevel":2,"defaultunlocked":false,"Extra":[]}]}';
        $saved = Progression::patch($this->instance(), self::PATH, $content, ['VBloodUnitSettings' => ['upsert' => [['UnitId' => 123, 'UnitLevel' => 20, 'DefaultUnlocked' => true]]]]);
        $boss = JsonGameConfigDocument::decode($saved)->VBloodUnitSettings[0];
        self::assertSame(20, $boss->unitlevel);
        self::assertTrue($boss->defaultunlocked);
        self::assertFalse(property_exists($boss, 'UnitId'));
        self::assertFalse(property_exists($boss, 'UnitLevel'));
        self::assertSame([], $boss->Extra);
    }

    #[DataProvider('malformedLists')]
    public function testMalformedExistingListIsReadOnlyAndOtherListsRemainUsable(string $content): void
    {
        $groups = array_column(Progression::describe($this->instance(), self::PATH, $content), null, 'key');
        self::assertFalse($groups['VBloodUnitSettings']['editable']);
        self::assertNotSame('', $groups['VBloodUnitSettings']['message']);
        $saved = Progression::patch($this->instance(), self::PATH, $content, ['UnlockedResearchs' => ['add' => [self::TIER1]]]);
        self::assertSame(json_encode(JsonGameConfigDocument::decode($content)->VBloodUnitSettings, JSON_THROW_ON_ERROR), json_encode(JsonGameConfigDocument::decode($saved)->VBloodUnitSettings, JSON_THROW_ON_ERROR));
        $this->expectException(\InvalidArgumentException::class);
        Progression::patch($this->instance(), self::PATH, $content, ['VBloodUnitSettings' => ['remove' => [123]]]);
    }

    public static function malformedLists(): iterable
    {
        yield ['{"VBloodUnitSettings":[{"UnitId":123,"unitid":456}]}'];
        yield ['{"VBloodUnitSettings":null}'];
        yield ['{"VBloodUnitSettings":{}}'];
        yield ['{"VBloodUnitSettings":[123]}'];
        yield ['{"VBloodUnitSettings":[{"UnitId":123,"UnitLevel":null}]}'];
        yield ['{"VBloodUnitSettings":[{"UnitId":123,"DefaultUnlocked":"true"}]}'];
        yield ['{"VBloodUnitSettings":[{"UnitId":123},{"UnitId":123}]}'];
    }

    #[DataProvider('invalidChanges')]
    public function testMalformedOrUndocumentedChangesAreRejected(mixed $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Progression::patch($this->instance(), self::PATH, '{}', $changes);
    }

    public static function invalidChanges(): iterable
    {
        yield [null];
        yield ['bad'];
        yield [['Other' => ['add' => [1]]]];
        yield [['UnlockedResearchs' => ['replace' => []]]];
        yield [['UnlockedResearchs' => ['add' => null]]];
        yield [['UnlockedResearchs' => ['add' => [123]]]];
        yield [['UnlockedResearchs' => ['add' => [(string) self::TIER1]]]];
        yield [['UnlockedResearchs' => ['add' => [self::TIER1, self::TIER1]]]];
        yield [['UnlockedResearchs' => ['add' => [self::TIER1], 'remove' => [self::TIER1]]]];
        yield [['UnlockedResearchs' => ['remove' => [123]]]];
        yield [['UnlockedResearchs' => ['add' => [2147483648]]]];
        yield [['UnlockedResearchs' => ['add' => array_fill(0, 257, self::TIER1)]]];
        foreach ([-1, 256, 1.5, '10', null] as $level) {
            yield [['VBloodUnitSettings' => ['upsert' => [['UnitId' => self::WOLF, 'UnitLevel' => $level, 'DefaultUnlocked' => false]]]]];
        }
        yield [['VBloodUnitSettings' => ['upsert' => [['UnitId' => self::WOLF, 'UnitLevel' => 10, 'DefaultUnlocked' => 'true']]]]];
        yield [['VBloodUnitSettings' => ['upsert' => [['UnitId' => self::WOLF, 'UnitLevel' => 10, 'DefaultUnlocked' => true, 'Extra' => true]]]]];
        yield [['VBloodUnitSettings' => ['upsert' => [['UnitId' => 123, 'UnitLevel' => 0, 'DefaultUnlocked' => false]]]]];
    }

    public function testTheFinalListSizeIsBoundedWithoutDroppingExistingEntries(): void
    {
        $content = json_encode(['UnlockedResearchs' => range(1, 512)], JSON_THROW_ON_ERROR);
        $this->expectException(\InvalidArgumentException::class);
        Progression::patch($this->instance(), self::PATH, $content, ['UnlockedResearchs' => ['add' => [self::TIER1]]]);
    }
}
