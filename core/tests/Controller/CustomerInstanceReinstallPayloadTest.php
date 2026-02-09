<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\InstanceActionMessage;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\SetupChecker;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Application\TemplateInstallResolver;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use App\Module\Ports\Infrastructure\Repository\PortBlockFinderInterface;
use App\Repository\BackupDefinitionRepository;
use App\Repository\BackupRepository;
use App\Repository\GamePluginRepository;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use App\Repository\MinecraftVersionCatalogRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class CustomerInstanceReinstallPayloadTest extends TestCase
{
    public function testReinstallPayloadUsesBuilderAndDisablesAutostart(): void
    {
        $instance = $this->buildInstance();
        $instanceRepository = $this->createMock(InstanceRepository::class);
        $instanceRepository->method('find')->willReturn($instance);

        $catalogRepo = new class () implements MinecraftVersionCatalogRepositoryInterface {
            public function findBuildsGroupedByVersion(string $channel): array
            {
                return [];
            }
            public function findLatestVersion(string $channel): ?string
            {
                return null;
            }
            public function findLatestBuild(string $channel, string $version): ?string
            {
                return null;
            }
            public function findEntry(string $channel, string $version, ?string $build): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog
            {
                return null;
            }
            public function versionExists(string $channel, string $version): bool
            {
                return false;
            }
            public function buildExists(string $channel, string $version, string $build): bool
            {
                return false;
            }
        };
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo));
        $portBlockFinder = new class () implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock
            {
                return null;
            }
        };
        $payloadBuilder = new InstanceJobPayloadBuilder($resolver, $portBlockFinder);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $captured = null;
        $messageBus->method('dispatch')->willReturnCallback(function (object $message) use (&$captured) {
            $captured = $message;
            return new Envelope($message, [new HandledStamp(['status' => 'queued'], 'handler')]);
        });

        $controller = new CustomerInstanceActionApiController(
            $instanceRepository,
            $this->createMock(BackupDefinitionRepository::class),
            $this->createMock(BackupRepository::class),
            $this->createMock(GamePluginRepository::class),
            $this->createMock(\App\Module\Ports\Infrastructure\Repository\PortBlockRepository::class),
            $this->createMock(JobRepository::class),
            $this->createMock(DiskEnforcementService::class),
            $this->createMock(\App\Module\Gameserver\Application\ConsoleCommandValidator::class),
            new SetupChecker(),
            $resolver,
            $payloadBuilder,
            $this->createMock(EntityManagerInterface::class),
            $messageBus,
        );

        $customer = $instance->getCustomer();
        $request = Request::create(
            '/api/instances/1/reinstall',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['confirm' => true], JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('current_user', $customer);

        $response = $controller->reinstall($request, 1);

        self::assertSame(202, $response->getStatusCode());
        self::assertInstanceOf(InstanceActionMessage::class, $captured);

        $payload = $captured->getPayload();
        self::assertSame('false', $payload['autostart'] ?? null);
        self::assertNotEmpty($payload['install_command'] ?? null);
        self::assertNotEmpty($payload['env_vars'] ?? null);
    }

    private function buildInstance(): Instance
    {
        $template = new Template(
            'game',
            'Game',
            null,
            222860,
            null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'udp']],
            '{{INSTANCE_DIR}}/srcds_run',
            [['key' => 'SERVER_NAME', 'value' => 'Test']],
            [],
            [],
            [],
            'steamcmd +app_update 222860 validate +quit',
            'steamcmd +app_update 222860 +quit',
            [],
            [],
            [],
            [],
            ['linux'],
            [],
            [],
        );

        $customer = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('node-1', [
            'key_id' => 'key-1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ]);
        $agent->recordHeartbeat(['os' => 'linux'], '1.0.0', null);

        $instance = new Instance(
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
        $instance->setSetupVars(['SERVER_NAME' => 'Test Server']);

        return $instance;
    }
}
