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
use App\Module\Gameserver\Application\Configuration\StructuredGameConfiguration;
use App\Module\Gameserver\Application\Configuration\VRisingHostConfiguration;
use App\Module\Gameserver\Application\MinecraftConfigurationPolicy;
use App\Repository\ConfigSchemaRepository;
use App\Repository\GameDefinitionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VRisingHostConfigurationTest extends TestCase
{
    private function instance(int $booked = 20, bool $locked = false, string $game = 'v_rising'): Instance
    {
        $template = new Template($game, 'V Rising', null, null, null, [], '', [], [], [], [], '', '', [], []);
        $instance = new Instance(new User('rising@example.test', UserType::Customer), $template, new Agent('rising-node', []), 1, 1024, 10, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
        $instance->setMaxSlots($booked);
        $instance->setCurrentSlots(16);
        $instance->setLockSlots($locked);

        return $instance;
    }

    #[DataProvider('limitChanges')]
    public function testPlayerAndAdminLimitsShareTheBookedCapacity(array $after, int $booked, bool $allowed): void
    {
        $instance = $this->instance($booked);
        if (!$allowed) {
            $this->expectException(\InvalidArgumentException::class);
        }
        MinecraftConfigurationPolicy::assertEditable($instance, 'save-data/Settings/ServerHostSettings.json', '{"MaxConnectedUsers":16,"MaxConnectedAdmins":4}', json_encode($after, JSON_THROW_ON_ERROR), true);
        if ($allowed) {
            self::assertSame($booked, $instance->getMaxSlots());
        }
    }

    public static function limitChanges(): iterable
    {
        yield 'full capacity split' => [['MaxConnectedUsers' => 12, 'MaxConnectedAdmins' => 8], 20, true];
        yield 'no admin reserve' => [['MaxConnectedUsers' => 20, 'MaxConnectedAdmins' => 0], 20, true];
        yield 'extra admin overbooking' => [['MaxConnectedUsers' => 20, 'MaxConnectedAdmins' => 1], 20, false];
        yield 'negative reserve' => [['MaxConnectedUsers' => 16, 'MaxConnectedAdmins' => -1], 20, false];
        yield 'fractional reserve' => [['MaxConnectedUsers' => 16, 'MaxConnectedAdmins' => 1.5], 20, false];
        yield 'missing reserve' => [['MaxConnectedUsers' => 12], 20, false];
        yield 'missing player limit' => [['MaxConnectedAdmins' => 0], 20, false];
        yield 'engine limit' => [['MaxConnectedUsers' => 129, 'MaxConnectedAdmins' => 0], 256, false];
        yield 'engine maximum' => [['MaxConnectedUsers' => 128, 'MaxConnectedAdmins' => 0], 256, true];
        yield 'overflow' => [['MaxConnectedUsers' => 16, 'MaxConnectedAdmins' => PHP_INT_MAX], 20, false];
    }

    public function testUnrelatedEditsDoNotChangeExistingCapacityAndRawEditsCannotBypassIt(): void
    {
        $instance = $this->instance();
        $path = 'save-data/Settings/ServerHostSettings.json';
        // An existing legacy reserve is left intact when only FPS changes.
        MinecraftConfigurationPolicy::assertEditable($instance, $path, '{"MaxConnectedUsers":16,"MaxConnectedAdmins":9,"ServerFps":30}', '{"ServerFps":15,"MaxConnectedAdmins":9,"MaxConnectedUsers":16}', true);
        MinecraftConfigurationPolicy::assertEditable($instance, $path, '{"MaxConnectedUsers":16,"MaxConnectedAdmins":9}', '{"MaxConnectedAdmins":9,"MaxConnectedUsers":16}');
        $this->expectException(\InvalidArgumentException::class);
        MinecraftConfigurationPolicy::assertEditable($instance, $path, '{"MaxConnectedUsers":16,"MaxConnectedAdmins":4}', '{"MaxConnectedUsers":16,"MaxConnectedAdmins":3}');
    }

    public function testSlotLocksAlsoProtectTheAdminReserve(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MinecraftConfigurationPolicy::assertEditable($this->instance(locked: true), 'ServerHostSettings.json', '{"MaxConnectedUsers":16,"MaxConnectedAdmins":4}', '{"MaxConnectedUsers":16,"MaxConnectedAdmins":3}', true);
    }

    #[DataProvider('smartKeepValues')]
    public function testRetentionRulesUseTheNativeTimeWindowFormat(string $value, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
        } else {
            self::expectNotToPerformAssertions();
        }
        MinecraftConfigurationPolicy::assertEditable($this->instance(), 'ServerHostSettings.json', '{}', json_encode(['AutoSaveSmartKeep' => $value], JSON_THROW_ON_ERROR), true);
    }

    public static function smartKeepValues(): iterable
    {
        yield 'disabled' => ['', true];
        yield 'one window' => ['10:1:1', true];
        yield 'multiple windows' => ['10:2:1,60:0:1,52560000:99:0', true];
        yield 'incomplete' => ['10:2', false];
        yield 'negative' => ['10:-1:1', false];
        yield 'overflow' => ['2147483648:1:1', false];
    }

    #[DataProvider('initialLimits')]
    public function testInitialLimitsUseConfiguredCapacityWithoutGuessingGameDefaults(int $booked, int $current, int $expected): void
    {
        $instance = $this->instance($booked);
        $instance->setCurrentSlots($current);
        $config = json_decode(VRisingHostConfiguration::initialConfig($instance, 'ServerHostSettings.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['MaxConnectedUsers' => $expected, 'MaxConnectedAdmins' => 0], $config);
        self::assertSame($booked, $instance->getMaxSlots());
    }

    public static function initialLimits(): iterable
    {
        yield 'configured player limit' => [20, 16, 16];
        yield 'new instance without player limit' => [20, 0, 20];
        yield 'engine maximum' => [256, 256, 128];
        yield 'booked maximum' => [20, 24, 20];
    }

    public function testExplicitCapacityOverridesStayUnderOperatorControl(): void
    {
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $configuration = new StructuredGameConfiguration(new ConfigSchemaRepository($registry), new GameDefinitionRepository($registry));
        $instance = $this->instance();
        $file = ['path' => 'ServerHostSettings.json', 'label' => 'Host', 'format' => 'json', 'schema' => []];
        $content = '{"MaxConnectedUsers":16,"MaxConnectedAdmins":4}';
        $instance->getTemplate()->setStartParams('VRisingServer.exe -serverName "Clan -maxUsers 999"');
        $fields = array_column($configuration->fields($instance, $file, $content), null, 'key');
        self::assertFalse($fields['MaxConnectedUsers']['readonly']);
        $instance->getTemplate()->setStartParams('VRisingServer.exe -maxUsers {{VR_MAX_USERS}}');
        $fields = array_column($configuration->fields($instance, $file, $content), null, 'key');
        self::assertTrue($fields['MaxConnectedUsers']['readonly']);
        self::assertTrue($fields['MaxConnectedAdmins']['readonly']);
        $this->expectException(\InvalidArgumentException::class);
        MinecraftConfigurationPolicy::assertEditable($instance, $file['path'], $content, '{"MaxConnectedUsers":12,"MaxConnectedAdmins":4}', true);
    }

    public function testNativeStartsBindReservedPortsWithoutEditingNamesPathsOrBindIp(): void
    {
        foreach (['v_rising' => 'VRisingServer', 'v_rising_windows' => 'VRisingServer.exe'] as $game => $binary) {
            $instance = $this->instance(game: $game);
            $instance->getTemplate()->setRequiredPorts([['name' => 'game'], ['name' => 'query']]);
            $command = '"/srv/game files/'.$binary.'" -serverName "Clan -gamePort 9999" -persistentDataPath "save data" -serverPort 9876 -gamePort 9988 -queryPort 9877 -address 192.0.2.10';
            $parameters = VRisingHostConfiguration::parameters($instance, $command);
            self::assertStringContainsString('-serverName "Clan -gamePort 9999"', $parameters);
            self::assertStringContainsString('-persistentDataPath "save data"', $parameters);
            self::assertStringContainsString('-address 192.0.2.10', $parameters);
            self::assertStringContainsString('-gamePort {{PORT_GAME}}', $parameters);
            self::assertStringContainsString('-queryPort {{PORT_QUERY}}', $parameters);
            self::assertStringNotContainsString('-serverPort 9876', $parameters);
            self::assertStringNotContainsString('-gamePort 9988', $parameters);
            self::assertStringNotContainsString('-queryPort 9877', $parameters);
            self::assertSame('wine VRisingServer.exe --custom', VRisingHostConfiguration::parameters($instance, 'wine VRisingServer.exe --custom'));
            $instance->getTemplate()->setRequiredPorts([]);
            self::assertSame($command, VRisingHostConfiguration::parameters($instance, $command));
        }
    }

    public function testEditorBoundsAndNetworkLocksComeFromTheNativeProfile(): void
    {
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $configuration = new StructuredGameConfiguration(new ConfigSchemaRepository($registry), new GameDefinitionRepository($registry));
        $instance = $this->instance(256, game: 'v_rising_windows');
        $file = ['path' => 'save-data/Settings/ServerHostSettings.json', 'label' => 'Host', 'format' => 'json', 'schema' => []];
        $content = '{"MaxConnectedUsers":128,"MaxConnectedAdmins":4,"Address":"0.0.0.0","Rcon":{"BindAddress":"127.0.0.1","Port":25575,"Enabled":true,"Password":"operator-secret"}}';
        $fields = array_column($configuration->fields($instance, $file, $content), null, 'key');
        self::assertSame(128, $fields['MaxConnectedUsers']['max']);
        self::assertSame(256, $fields['MaxConnectedAdmins']['max']);
        self::assertSame(0, $fields['MaxConnectedAdmins']['min']);
        foreach (['Address', 'Rcon.BindAddress', 'Rcon.Port', 'Rcon.Enabled', 'Rcon.Password'] as $key) {
            self::assertTrue($fields[$key]['readonly']);
        }
        self::assertSame('', $fields['Rcon.Password']['value']);
        $available = array_column($configuration->availableFields($instance, $file, $content), null, 'key');
        self::assertArrayHasKey('ServerFps', $available);
        self::assertArrayHasKey('LowerFPSWhenEmpty', $available);
        self::assertArrayHasKey('GameDifficultyPreset', $available);
        $instance->setLockSlots(true);
        $locked = array_column($configuration->fields($instance, $file, $content), null, 'key');
        self::assertTrue($locked['MaxConnectedAdmins']['readonly']);
    }
}
