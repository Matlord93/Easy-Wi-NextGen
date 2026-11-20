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
use App\Module\Gameserver\Application\Configuration\EnshroudedConfiguration;
use App\Module\Gameserver\Application\Configuration\EnshroudedRoleConfiguration;
use App\Module\Gameserver\Application\Configuration\GameConfigDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnshroudedRoleConfigurationTest extends TestCase
{
    private const string PATH = 'enshrouded_server.json';
    private const string CONTENT = '{"name":"World","slotCount":8,"userGroups":[{"name":"Admin","password":"existing-admin","reservedSlots":2,"canKickBan":true,"Mod":{"empty":{},"list":[null,1]}},{"name":"Friend","password":"existing-friend","reservedSlots":0,"canEditWorld":true}],"Unknown":{"empty":{},"list":[]}}';

    private function instance(string $game = 'enshrouded'): Instance
    {
        $instance = new Instance(new User('roles@example.test', UserType::Customer), new Template($game, 'Enshrouded', null, null, null, [], '', [], [], [], [], '', '', [], []), new Agent('node', []), 1, 4096, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
        $instance->setMaxSlots(12);

        return $instance;
    }

    public function testMixedEditsUseOriginalIndicesAndKeepAdditionalDataAndNativeTypes(): void
    {
        $instance = $this->instance();
        $edited = GameConfigDocument::patch(self::CONTENT, 'json', ['userGroups.1.name' => 'Friends renamed', 'userGroups.1.password' => 'changed password', 'name' => 'New world']);
        $content = EnshroudedRoleConfiguration::patch($instance, self::PATH, self::CONTENT, $edited, ['remove' => [0], 'add' => [['name' => ' Builder ', 'password' => ' new secret ', 'reservedSlots' => 2, 'canEditBase' => true]]]);
        $data = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
        self::assertSame('New world', $data->name);
        self::assertCount(2, $data->userGroups);
        self::assertSame('Friends renamed', $data->userGroups[0]->name);
        self::assertSame('changed password', $data->userGroups[0]->password);
        self::assertTrue($data->userGroups[0]->canEditWorld);
        self::assertSame('Builder', $data->userGroups[1]->name);
        self::assertSame(' new secret ', $data->userGroups[1]->password);
        self::assertTrue($data->userGroups[1]->canEditBase);
        self::assertFalse($data->userGroups[1]->canKickBan);
        self::assertFalse($data->userGroups[1]->canEditWorld);
        self::assertSame(2, $data->userGroups[1]->reservedSlots);
        self::assertObjectNotHasProperty('allowPublicAccess', $data->userGroups[1]);
        self::assertInstanceOf(\stdClass::class, $data->Unknown->empty);
        self::assertSame([], $data->Unknown->list);
        EnshroudedConfiguration::assertSetupChange($instance, self::PATH, self::CONTENT, $content);
    }

    public function testAddingRolePreservesExistingSecretsPermissionsAndModStructures(): void
    {
        $content = EnshroudedRoleConfiguration::patch($this->instance(), self::PATH, self::CONTENT, self::CONTENT, ['add' => [['name' => 'Public', 'password' => '', 'allowPublicAccess' => true]]]);
        $before = json_decode(self::CONTENT, false, flags: JSON_THROW_ON_ERROR);
        $after = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
        self::assertEquals($before->userGroups[0], $after->userGroups[0]);
        self::assertEquals($before->userGroups[1], $after->userGroups[1]);
        self::assertSame('', $after->userGroups[2]->password);
        self::assertFalse($after->userGroups[2]->canAccessInventories);
        $descriptor = EnshroudedRoleConfiguration::describe($this->instance(), self::PATH, $content);
        self::assertTrue($descriptor['editable']);
        self::assertTrue($descriptor['entries'][2]['public_access']);
        self::assertSame(8, $descriptor['slot_count']);
        self::assertStringNotContainsString('existing-admin', json_encode($descriptor, JSON_THROW_ON_ERROR));
    }

    public function testLastRoleCanBeExplicitlyReplacedAndNewPasswordCanReuseRemovedAccess(): void
    {
        $content = EnshroudedRoleConfiguration::patch($this->instance('enshrouded_windows'), self::PATH, self::CONTENT, self::CONTENT, ['remove' => [1, 0], 'add' => [['name' => 'Admin', 'password' => 'existing-admin']]]);
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['userGroups']);
        self::assertSame('Admin', $data['userGroups'][0]['name']);
        self::assertFalse($data['userGroups'][0]['canKickBan']);
    }

    #[DataProvider('invalidChanges')]
    public function testInvalidChangesCannotSilentlyOverwriteRoles(mixed $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnshroudedRoleConfiguration::patch($this->instance(), self::PATH, self::CONTENT, self::CONTENT, $changes);
    }

    public static function invalidChanges(): iterable
    {
        yield 'missing password consent' => [['add' => [['name' => 'Public', 'password' => '']]]];
        yield 'string consent' => [['add' => [['name' => 'Public', 'password' => '', 'allowPublicAccess' => 'true']]]];
        yield 'duplicate existing password' => [['add' => [['name' => 'New', 'password' => 'existing-admin']]]];
        yield 'duplicate case folded name' => [['add' => [['name' => ' admin ', 'password' => 'new']]]];
        yield 'duplicate new password' => [['add' => [['name' => 'One', 'password' => 'new'], ['name' => 'Two', 'password' => 'new']]]];
        yield 'duplicate new name' => [['add' => [['name' => 'New', 'password' => 'one'], ['name' => 'NEW', 'password' => 'two']]]];
        yield 'string permission' => [['add' => [['name' => 'New', 'password' => 'new', 'canKickBan' => 'true']]]];
        yield 'null permission' => [['add' => [['name' => 'New', 'password' => 'new', 'canEditBase' => null]]]];
        yield 'string reservation' => [['add' => [['name' => 'New', 'password' => 'new', 'reservedSlots' => '2']]]];
        yield 'reservation beyond actual limit' => [['add' => [['name' => 'New', 'password' => 'new', 'reservedSlots' => 9]]]];
        yield 'unknown property' => [['add' => [['name' => 'New', 'password' => 'new', 'isAdmin' => true]]]];
        yield 'empty name' => [['add' => [['name' => ' ', 'password' => 'new']]]];
        yield 'long name' => [['add' => [['name' => str_repeat('ä', 65), 'password' => 'new']]]];
        yield 'multiline password' => [['add' => [['name' => 'New', 'password' => "new\nsecret"]]]];
        yield 'negative removal' => [['remove' => [-1]]];
        yield 'nonexistent removal' => [['remove' => [2]]];
        yield 'string index' => [['remove' => ['0']]];
        yield 'duplicate removal' => [['remove' => [0, 0]]];
        yield 'remove last roles' => [['remove' => [0, 1]]];
        yield 'null operations' => [null];
        yield 'null add' => [['add' => null]];
        yield 'object-shaped list' => [['add' => ['name' => 'New', 'password' => 'new']]];
        yield 'unknown operation' => [['replace' => []]];
        yield 'too many additions' => [['add' => array_fill(0, 33, ['name' => 'New', 'password' => 'new'])]];
    }

    #[DataProvider('readOnlyDocuments')]
    public function testLegacyOrMalformedRolesRemainReadOnlyAndAreNotConverted(string $content): void
    {
        $instance = $this->instance();
        self::assertFalse(EnshroudedRoleConfiguration::describe($instance, self::PATH, $content)['editable']);
        self::assertSame($content, EnshroudedRoleConfiguration::patch($instance, self::PATH, $content, $content, []));
        $this->expectException(\InvalidArgumentException::class);
        EnshroudedRoleConfiguration::patch($instance, self::PATH, $content, $content, ['add' => [['name' => 'New', 'password' => 'new']]]);
    }

    public static function readOnlyDocuments(): iterable
    {
        yield ['{"slotCount":8,"password":"legacy"}'];
        yield ['{"slotCount":8,"password":"legacy","userGroups":[]}'];
        yield ['{"slotCount":8,"userGroups":{}}'];
        yield ['{"slotCount":8,"userGroups":null}'];
        yield ['{"slotCount":8,"UserGroups":[]}'];
        yield ['{"slotCount":8,"userGroups":[{"name":false,"password":"secret"}]}'];
        yield ['{"slotCount":8,"userGroups":[{"name":"Name","password":null}]}'];
        yield ['{"slotCount":8,"userGroups":[null]}'];
    }

    public function testEmptyNativeDocumentCanCreateRolesWhenCapacityIsExplicit(): void
    {
        $before = '{}';
        $edited = '{"slotCount":4}';
        $saved = EnshroudedRoleConfiguration::patch($this->instance(), self::PATH, $before, $edited, ['add' => [['name' => 'New', 'password' => 'new', 'reservedSlots' => 4]]]);
        self::assertSame(4, json_decode($saved, true, flags: JSON_THROW_ON_ERROR)['userGroups'][0]['reservedSlots']);
        $this->expectException(\InvalidArgumentException::class);
        EnshroudedRoleConfiguration::patch($this->instance(), self::PATH, $before, $before, ['add' => [['name' => 'New', 'password' => 'new']]]);
    }

    public function testLegacyPasswordCannotBeBypassedByClearingItInSameBatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnshroudedRoleConfiguration::patch($this->instance(), self::PATH, '{"password":"legacy","slotCount":4}', '{"password":"","slotCount":4}', ['add' => [['name' => 'New', 'password' => 'new']]]);
    }

    public function testOtherGamesAndOtherFilesHaveNoRoleManager(): void
    {
        self::assertNull(EnshroudedRoleConfiguration::describe($this->instance('vrising'), self::PATH, self::CONTENT));
        self::assertNull(EnshroudedRoleConfiguration::describe($this->instance(), 'other.json', self::CONTENT));
        $this->expectException(\InvalidArgumentException::class);
        EnshroudedRoleConfiguration::patch($this->instance('vrising'), self::PATH, self::CONTENT, self::CONTENT, ['remove' => [0]]);
    }
}
