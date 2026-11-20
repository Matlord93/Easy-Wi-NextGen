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
use App\Module\Gameserver\Application\Configuration\EnshroudedBanList;
use App\Module\Gameserver\Application\Configuration\EnshroudedConfiguration;
use App\Module\Gameserver\Application\Configuration\EnshroudedServerTags;
use App\Module\Gameserver\Application\Configuration\GameConfigDocument;
use App\Module\Gameserver\Application\Configuration\GameConfigurationAdditions;
use App\Module\Gameserver\Application\Configuration\GameSetupProfiles;
use App\Module\Gameserver\Application\Configuration\StructuredGameConfiguration;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnshroudedConfigurationTest extends TestCase
{
    private const array FILE = ['path' => 'enshrouded_server.json', 'label' => 'Enshrouded', 'format' => 'json', 'schema' => []];

    private function instance(string $game = 'enshrouded', int $booked = 20): Instance
    {
        $instance = new Instance(new User('shroud@example.test', UserType::Customer), new Template($game, 'Enshrouded', null, null, null, [], '', [], [], [], [], '', '', [], []), new Agent('shroud-node', []), 1, 4096, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
        $instance->setMaxSlots($booked);
        $instance->setCurrentSlots(min(16, $booked));

        return $instance;
    }

    private function configuration(): StructuredGameConfiguration
    {
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);

        return new StructuredGameConfiguration(new ConfigSchemaRepository($registry), new GameDefinitionRepository($registry));
    }

    private function fixture(): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/gameserver/enshrouded-official-default.json');
    }

    public function testMalformedBanListsStayVisibleWithoutExposingUnknownFields(): void
    {
        $path = self::FILE['path'];
        $content = '{"bannedAccounts":{"data":"opaque"},"bans":[{"displayName":"Player","banDate":-1},{"characterName":"Traveler","banDate":253402300800}]}';
        $lists = array_column(EnshroudedBanList::describe($this->instance(), $path, $content), null, 'key');
        self::assertFalse($lists['bannedAccounts']['list_valid']);
        self::assertSame([], $lists['bannedAccounts']['entries']);
        self::assertTrue($lists['bans']['list_valid']);
        self::assertSame([null, null], array_column($lists['bans']['entries'], 'ban_date'));
        self::assertNull(EnshroudedBanList::describe($this->instance('vrising'), $path, $content));
    }

    public function testTagSelectionCanCreateAMissingListAndRemoveTheLastSelectedTag(): void
    {
        $instance = $this->instance();
        $original = '{"name":"World"}';
        self::assertSame($original, EnshroudedServerTags::patch($instance, self::FILE, $original, ['English' => false]));
        $saved = EnshroudedServerTags::patch($instance, self::FILE, $original, ['English' => true]);
        self::assertSame(['English'], json_decode($saved, true, flags: JSON_THROW_ON_ERROR)['tags']);
        $cleared = EnshroudedServerTags::patch($instance, self::FILE, $saved, ['English' => false]);
        self::assertSame([], json_decode($cleared, false, flags: JSON_THROW_ON_ERROR)->tags);
    }

    #[DataProvider('malformedTags')]
    public function testTagSelectionDoesNotReplaceUnexpectedExistingData(string $content): void
    {
        $instance = $this->instance();
        self::assertSame([], EnshroudedServerTags::describe($instance, self::FILE, $content)['groups']);
        $this->expectException(\InvalidArgumentException::class);
        EnshroudedServerTags::patch($instance, self::FILE, $content, ['English' => true]);
    }

    public static function malformedTags(): iterable
    {
        yield ['{"Tags":["English"]}'];
        yield ['{"tags":{"0":"English"}}'];
        yield ['{"tags":["English",1]}'];
        yield ['{"tags":null}'];
    }

    public function testTagSelectionDefersToExplicitOperatorSchema(): void
    {
        $instance = $this->instance();
        $file = self::FILE;
        $file['schema'] = ['tags.0' => ['readonly' => true]];
        $content = '{"tags":["English"]}';
        self::assertNull(EnshroudedServerTags::describe($instance, $file, $content));
        self::assertNull(EnshroudedServerTags::describe($this->instance('vrising'), self::FILE, $content));
        self::assertTrue($this->configuration()->fields($instance, $file, $content)[0]['readonly']);
        $this->expectException(\InvalidArgumentException::class);
        EnshroudedServerTags::patch($instance, $file, $content, ['English' => false]);
    }

    public function testPublisherDefaultsCoverAllDocumentedGameplayFieldsAndDistinctRoles(): void
    {
        $configuration = $this->configuration();
        $instance = $this->instance();
        $content = $this->fixture();
        $native = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(37, $native['gameSettings']);
        foreach (array_keys($native['gameSettings']) as $key) {
            self::assertSame('gameSettings.'.$key, GameSetupProfiles::field('enshrouded', 'enshrouded_server.json', 'gameSettings.'.$key)['write_key']);
        }
        $fields = array_column($configuration->fields($instance, self::FILE, $content), null, 'key');
        self::assertSame('30', $fields['gameSettings.dayTimeDuration']['value']);
        self::assertSame('12', $fields['gameSettings.nightTimeDuration']['value']);
        self::assertSame('10', $fields['gameSettings.fromHungerToStarving']['value']);
        self::assertSame(2.0, $fields['gameSettings.dayTimeDuration']['min']);
        self::assertSame(60.0, $fields['gameSettings.dayTimeDuration']['max']);
        self::assertSame('Rolle: Admin', $fields['userGroups.0.name']['category']);
        self::assertSame('Rolle: Friend', $fields['userGroups.1.name']['category']);
        self::assertSame('Rollenname', $fields['userGroups.0.name']['label']);
        self::assertArrayNotHasKey('startup_var', GameSetupProfiles::field('enshrouded_windows', 'enshrouded_server.json', 'userGroups.0.name'));
        self::assertSame('SERVER_NAME', GameSetupProfiles::field('enshrouded_windows', 'enshrouded_server.json', 'name')['startup_var']);
        $available = array_column($configuration->availableFields($instance, self::FILE, $content), null, 'key');
        self::assertSame(['gameSettings.flameLevelFogRemoval'], array_keys($available));
        self::assertSame('integer', $available['gameSettings.flameLevelFogRemoval']['native_type']);
        $file = self::FILE;
        $file['schema'] = ['userGroups.0.name' => ['category' => 'Eigene Zugänge']];
        $custom = array_column($configuration->fields($instance, $file, $content), null, 'key');
        self::assertSame('Eigene Zugänge', $custom['userGroups.0.name']['category']);
    }

    #[DataProvider('durationValues')]
    public function testMinutesAreStoredAsNativeIntegerNanoseconds(string $minutes, int $nanoseconds): void
    {
        $configuration = $this->configuration();
        $instance = $this->instance();
        $content = $this->fixture();
        $fields = $configuration->fields($instance, self::FILE, $content);
        $changes = $configuration->validate($fields, ['gameSettingsPreset' => 'Custom', 'gameSettings.dayTimeDuration' => $minutes]);
        self::assertSame((string) $nanoseconds, $changes['gameSettings.dayTimeDuration']);
        $saved = GameConfigDocument::patch($content, 'json', $changes);
        EnshroudedConfiguration::assertSetupChange($instance, 'enshrouded_server.json', $content, $saved);
        $data = json_decode($saved, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($nanoseconds, $data['gameSettings']['dayTimeDuration']);
        self::assertSame(720000000000, $data['gameSettings']['nightTimeDuration']);
        self::assertSame(json_decode($content, true, flags: JSON_THROW_ON_ERROR)['userGroups'], $data['userGroups']);
    }

    public static function durationValues(): iterable
    {
        yield ['2', 120000000000];
        yield ['2.5', 150000000000];
        yield ['30', 1800000000000];
        yield ['59.75', 3585000000000];
        yield ['60', 3600000000000];
        yield ['2.000000000016667', 120000000001];
    }

    public function testAddingMissingTimesUsesNativeIntegersAndPreservesUnknownStructures(): void
    {
        $configuration = $this->configuration();
        $instance = $this->instance();
        $content = '{"gameSettingsPreset":"Custom","Custom":{"empty":{},"list":[null,1]},"bans":[]}';
        $available = $configuration->availableFields($instance, self::FILE, $content);
        $byKey = array_column($available, null, 'key');
        self::assertSame('decimal', $byKey['gameSettings.dayTimeDuration']['type']);
        self::assertSame('integer', $byKey['gameSettings.dayTimeDuration']['native_type']);
        $changes = $configuration->validate($available, ['gameSettings.dayTimeDuration' => '2.5', 'gameSettings.fromHungerToStarving' => '6.25']);
        $prepared = GameConfigurationAdditions::prepare($content, 'json', $changes, $available);
        $saved = GameConfigDocument::patch($prepared, 'json', $changes);
        EnshroudedConfiguration::assertSetupChange($instance, 'enshrouded_server.json', $content, $saved);
        $data = json_decode($saved, false, flags: JSON_THROW_ON_ERROR);
        self::assertSame(150000000000, $data->gameSettings->dayTimeDuration);
        self::assertSame(375000000000, $data->gameSettings->fromHungerToStarving);
        self::assertInstanceOf(\stdClass::class, $data->Custom->empty);
        self::assertSame([null, 1], $data->Custom->list);
        self::assertSame([], $data->bans);
    }

    #[DataProvider('invalidValues')]
    public function testDocumentedBoundsAndOptionsAreEnforced(string $key, string $value): void
    {
        $configuration = $this->configuration();
        $fields = $configuration->fields($this->instance(), self::FILE, $this->fixture());
        $this->expectException(\InvalidArgumentException::class);
        $configuration->validate($fields, [$key => $value]);
    }

    public static function invalidValues(): iterable
    {
        yield ['gameSettings.dayTimeDuration', '1.99'];
        yield ['gameSettings.nightTimeDuration', '60.01'];
        yield ['gameSettings.dayTimeDuration', '1e999'];
        yield ['gameSettings.fromHungerToStarving', '4.99'];
        yield ['gameSettings.fromHungerToStarving', '20.01'];
        yield ['gameSettings.playerBodyHeatFactor', '0.75'];
        yield ['gameSettings.perkUpgradeRecyclingFactor', '1.01'];
        yield ['gameSettings.perkCostFactor', '0'];
        yield ['gameSettings.enemyPerceptionRangeFactor', '3'];
        yield ['gameSettings.threatBonus', '4.01'];
        yield ['gameSettings.aggroPoolAmount', 'Unlimited'];
        yield ['gameSettings.tamingStartleRepercussion', 'None'];
        yield ['gameSettings.curseModifier', 'Disabled'];
        yield ['voiceChatMode', 'Team'];
        yield ['slotCount', '17'];
        yield ['userGroups.0.reservedSlots', '17'];
    }

    public function testCustomIsRequiredOnlyForChangedIndividualRulesAndNeverSelectedAutomatically(): void
    {
        $instance = $this->instance();
        EnshroudedConfiguration::assertSetupChange($instance, 'enshrouded_server.json', '{"gameSettingsPreset":"Default","gameSettings":{"playerHealthFactor":1}}', '{"gameSettingsPreset":"Default","gameSettings":{"playerHealthFactor":1},"voiceChatMode":"Global"}');
        EnshroudedConfiguration::assertSetupChange($instance, 'enshrouded_server.json', '{}', '{"gameSettingsPreset":"Custom","gameSettings":{"playerHealthFactor":2}}');
        $this->expectException(\InvalidArgumentException::class);
        EnshroudedConfiguration::assertSetupChange($instance, 'enshrouded_server.json', '{"gameSettingsPreset":"Hard"}', '{"gameSettingsPreset":"Hard","gameSettings":{"playerHealthFactor":2}}');
    }

    public function testRoleReservationsShareTheActualLimitAndNamesDoNotTriggerResourceChecks(): void
    {
        $instance = $this->instance(booked: 6);
        $configuration = $this->configuration();
        $before = '{"slotCount":4,"userGroups":[{"name":"Admin","reservedSlots":3}]}';
        $fields = array_column($configuration->fields($instance, self::FILE, $before), null, 'key');
        self::assertSame(4, $fields['userGroups.0.reservedSlots']['max']);
        EnshroudedConfiguration::assertSetupChange($instance, 'enshrouded_server.json', $before, '{"slotCount":4,"userGroups":[{"name":"Owner","reservedSlots":3}]}');
        EnshroudedConfiguration::assertSetupChange($instance, 'enshrouded_server.json', $before, '{"slotCount":2,"userGroups":[{"name":"Admin","reservedSlots":2}]}');
        $this->expectException(\InvalidArgumentException::class);
        EnshroudedConfiguration::assertSetupChange($instance, 'enshrouded_server.json', $before, '{"slotCount":2,"userGroups":[{"name":"Admin","reservedSlots":3}]}');
    }

    public function testBooleanPasswordProtectionFlagsRemainSwitchesInOtherGames(): void
    {
        $configuration = $this->configuration();
        $file = ['path' => 'R5/ServerDescription.json', 'label' => 'Windrose', 'format' => 'json', 'schema' => []];
        $available = $configuration->availableFields($this->instance('windrose'), $file, '{}');
        $field = array_column($available, null, 'key')['ServerDescription_Persistent.IsPasswordProtected'];
        self::assertSame('boolean', $field['type']);
        $changes = $configuration->validate($available, [$field['key'] => 'true']);
        $prepared = GameConfigurationAdditions::prepare('{}', 'json', $changes, $available);
        $saved = json_decode(GameConfigDocument::patch($prepared, 'json', $changes), false, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($saved->ServerDescription_Persistent->IsPasswordProtected);
    }
}
