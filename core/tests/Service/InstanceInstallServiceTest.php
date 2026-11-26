<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\SetupChecker;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceInstallService;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Module\Gameserver\Application\Query\InstanceQueryResolver;
use App\Module\Gameserver\Application\TemplateInstallResolver;
use App\Module\Gameserver\Application\TemplateInstallResolverInterface;
use App\Module\Ports\Application\PortLeaseManagerInterface;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepositoryInterface;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepositoryInterface;
use App\Repository\SharedStorageTemplateLocatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class InstanceInstallServiceTest extends TestCase
{
    public function testSevenDaysInstallationAndRestartUseTheSameExistingPortBlock(): void
    {
        $instance = $this->buildInstance([], ['linux'], 'linux', startParams: '{{INSTANCE_DIR}}/startserver.sh -configfile=serverconfig.xml -quit -batchmode -nographics -dedicated');
        $template = $instance->getTemplate();
        $template->setGameKey('seven_days_to_die');
        $template->setSteamAppId(294420);
        $template->setRequiredPorts([
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
            ['name' => 'rcon', 'protocol' => 'tcp'],
        ]);
        $instance->getNode()->recordHeartbeat(['os' => 'linux'], '1.0.0', '192.0.2.1');
        $pool = new PortPool($instance->getNode(), '7DTD', 'games', 27000, 27100);
        $block = new PortBlock($pool, $instance->getCustomer(), 27021, 27023);
        $instance->setPortBlockId($block->getId());
        $blocks = $this->createStub(PortBlockRepositoryInterface::class);
        $blocks->method('find')->willReturn($block);
        $blocks->method('findByInstance')->willReturn($block);
        $resolver = $this->createStub(TemplateInstallResolverInterface::class);
        $resolver->method('resolveInstallCommand')->willReturn('install');
        $resolver->method('resolveUpdateCommand')->willReturn('update');
        $locator = $this->createStub(SharedStorageTemplateLocatorInterface::class);
        $installer = new InstanceInstallService(new SetupChecker(), $this->createStub(PortPoolRepositoryInterface::class), $blocks, $this->createStub(PortLeaseManagerInterface::class), $resolver, $locator, $this->createStub(EntityManagerInterface::class));
        $builder = new InstanceJobPayloadBuilder($resolver, $blocks, $locator);

        $install = $installer->prepareInstall($instance);
        self::assertTrue($install['ok']);
        $runtime = $builder->buildRuntimePayload($instance);
        foreach ([$install['payload'], $runtime, $builder->buildSniperUpdatePayload($instance)] as $payload) {
            self::assertSame('seven_days_to_die', $payload['game_key']);
            self::assertSame('true', $payload['seven_days_to_die_managed_ports']);
            self::assertSame([27021, 27022, 27023], $payload['ports']);
            $ports = array_column($payload['port_reservations'], null, 'role');
            self::assertSame(['role' => 'game', 'protocol' => 'udp', 'port' => 27021], $ports['game']);
            self::assertSame(['role' => 'game_tcp', 'protocol' => 'tcp', 'port' => 27021], $ports['game_tcp']);
            self::assertSame(['role' => 'webdashboard', 'protocol' => 'tcp', 'port' => 27022], $ports['webdashboard']);
            self::assertSame(['role' => 'telnet', 'protocol' => 'tcp', 'port' => 27023], $ports['telnet']);
            self::assertSame(['role' => 'litenetlib', 'protocol' => 'udp', 'port' => 27023], $ports['litenetlib']);
        }
        $instance->setSetupVars(['GAME_PORT' => '26900', 'SV_QUERYPORT' => '27022']);
        self::assertSame(27021, (new InstanceQueryResolver())->resolve($instance, $block)->getPort());

        $template->setStartParams('custom-wrapper --config settings.xml');
        $custom = $builder->buildRuntimePayload($instance);
        self::assertArrayNotHasKey('seven_days_to_die_managed_ports', $custom);
        self::assertSame(['game', 'query', 'rcon'], array_column($custom['port_reservations'], 'role'));
    }

    #[TestWith(['cs2', '{{INSTANCE_DIR}}/game/cs2.sh', '+host_workshop_map'])]
    #[TestWith(['csgo_legacy', '{{INSTANCE_DIR}}/srcds_run -game csgo', '+workshop_start_map'])]
    public function testSourceInstallationOnlyPassesConfiguredWorkshopArguments(string $game, string $command, string $mapFlag): void
    {
        $instance = $this->buildInstance([], ['linux'], 'linux', startParams: $command.' +map de_dust2 +host_workshop_collection {{ WORKSHOP_COLLECTION }} +host_workshop_map {{ WORKSHOP_MAP }}');
        $instance->getTemplate()->setGameKey($game);
        $instance->setInstanceBaseDir('/srv/games');
        $instance->setInstallPath('/srv/games/gs342/counter-strike');
        $service = $this->buildService();

        $result = $service->prepareInstall($instance);
        self::assertTrue($result['ok']);
        self::assertSame('/srv/games', $result['payload']['base_dir']);
        self::assertSame('/srv/games/gs342/counter-strike', $result['payload']['install_path']);
        self::assertStringNotContainsString('workshop_', $result['payload']['start_params']);
        self::assertStringNotContainsString('WORKSHOP_', $result['payload']['start_params']);
        self::assertStringContainsString('+map {{MAP}}', $result['payload']['start_params']);
        self::assertStringContainsString('+exec server.cfg', $result['payload']['start_params']);
        self::assertSame('de_dust2', array_column($result['payload']['env_vars'], 'value', 'key')['MAP']);

        $instance->setSetupVars(['WORKSHOP_COLLECTION' => '123456', 'WORKSHOP_MAP' => '234567']);
        $result = $service->prepareInstall($instance);
        self::assertTrue($result['ok']);
        self::assertStringContainsString('+host_workshop_collection {{WORKSHOP_COLLECTION}}', $result['payload']['start_params']);
        self::assertStringContainsString($mapFlag.' {{WORKSHOP_MAP}}', $result['payload']['start_params']);
        $vars = array_column($result['payload']['env_vars'], 'value', 'key');
        self::assertSame('123456', $vars['WORKSHOP_COLLECTION']);
        self::assertSame('234567', $vars['WORKSHOP_MAP']);
    }

    public function testInstallStatusRequiresSetupCompletion(): void
    {
        $service = $this->buildService();
        $instance = $this->buildInstance(
            requirementVars: [
                [
                    'key' => 'server_name',
                    'label' => 'Server Name',
                    'type' => 'text',
                    'required' => true,
                    'scope' => 'customer_allowed',
                ],
            ],
            supportedOs: ['linux'],
            nodeOs: 'linux',
        );

        $status = $service->getInstallStatus($instance);

        self::assertFalse($status['is_ready']);
        self::assertSame('MISSING_REQUIREMENTS', $status['error_code'] ?? null);
    }

    public function testInstallStatusRejectsUnsupportedOs(): void
    {
        $service = $this->buildService();
        $instance = $this->buildInstance(
            requirementVars: [],
            supportedOs: ['linux'],
            nodeOs: 'windows',
        );

        $status = $service->getInstallStatus($instance);

        self::assertFalse($status['is_ready']);
        self::assertSame('TEMPLATE_OS_MISMATCH', $status['error_code'] ?? null);
    }

    public function testPrepareInstallWithSharedStorageReturnsErrorWhenNotSupported(): void
    {
        $locator = $this->createStub(SharedStorageTemplateLocatorInterface::class);
        $locator->method('findSharedStorageVariantForIdentity')->willReturn(null);

        $service = $this->buildService(sharedStorageLocator: $locator);
        $instance = $this->buildInstance(
            requirementVars: [],
            supportedOs: ['linux'],
            nodeOs: 'linux',
            installCommand: 'install',
            startParams: 'start',
        );

        $result = $service->prepareInstall($instance, true);

        self::assertFalse($result['ok']);
        self::assertSame('SHARED_STORAGE_NOT_SUPPORTED', $result['error_code'] ?? null);
    }

    public function testPrepareInstallWithSharedStorageIncludesPayloadWhenSupported(): void
    {
        $sharedTemplate = new Template(
            'game',
            'Game',
            null,
            null,
            null,
            [],
            'start',
            [],
            [],
            [],
            [],
            'install',
            'update',
            [],
            [],
            [],
            [],
            ['linux'],
            [],
            ['shared_paths' => [['source' => 'maps', 'target' => 'maps', 'mode' => 'symlink', 'readonly' => true]]],
        );

        $locator = $this->createStub(SharedStorageTemplateLocatorInterface::class);
        $locator->method('findSharedStorageVariantForIdentity')->willReturn($sharedTemplate);

        $service = $this->buildService(sharedStorageLocator: $locator);
        $instance = $this->buildInstance(
            requirementVars: [],
            supportedOs: ['linux'],
            nodeOs: 'linux',
            installCommand: 'install',
            startParams: 'start',
        );

        $result = $service->prepareInstall($instance, true);

        self::assertTrue($result['ok']);
        self::assertSame('true', $result['payload']['use_shared_storage'] ?? null);
        self::assertNotEmpty($result['payload']['shared_paths'] ?? []);
    }

    private function buildService(?SharedStorageTemplateLocatorInterface $sharedStorageLocator = null): InstanceInstallService
    {
        $resolver = $this->createStub(TemplateInstallResolverInterface::class);
        $resolver->method('resolveInstallCommand')->willReturn('install');
        $resolver->method('resolveUpdateCommand')->willReturn('update');

        return new InstanceInstallService(
            new SetupChecker(),
            $this->createStub(PortPoolRepositoryInterface::class),
            $this->createStub(PortBlockRepositoryInterface::class),
            $this->createStub(PortLeaseManagerInterface::class),
            $resolver,
            $sharedStorageLocator ?? $this->createStub(SharedStorageTemplateLocatorInterface::class),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $requirementVars
     * @param string[] $supportedOs
     */
    private function buildInstance(
        array $requirementVars,
        array $supportedOs,
        string $nodeOs,
        string $installCommand = 'install',
        string $startParams = 'start',
    ): Instance {
        $template = new Template(
            'game',
            'Game',
            null,
            null,
            null,
            [],
            $startParams,
            [],
            [],
            [],
            [],
            $installCommand,
            'update',
            [],
            [],
            $requirementVars,
            [],
            $supportedOs,
            [],
            [],
        );

        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', [
            'key_id' => 'key-1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ]);
        $agent->recordHeartbeat(['os' => $nodeOs], '1.0.0', null);

        return new Instance(
            $customer,
            $template,
            $agent,
            100,
            1024,
            10240,
            null,
            InstanceStatus::PendingSetup,
            InstanceUpdatePolicy::Manual,
        );
    }
}
