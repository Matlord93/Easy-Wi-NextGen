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
use App\Module\Gameserver\Application\TemplateInstallResolver;
use App\Module\Ports\Application\PortLeaseManager;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Repository\SharedStorageTemplateLocatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class InstanceInstallServiceTest extends TestCase
{
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
        $locator = $this->createMock(SharedStorageTemplateLocatorInterface::class);
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

        $locator = $this->createMock(SharedStorageTemplateLocatorInterface::class);
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
        $resolver = $this->createMock(TemplateInstallResolver::class);
        $resolver->method('resolveInstallCommand')->willReturn('install');
        $resolver->method('resolveUpdateCommand')->willReturn('update');

        return new InstanceInstallService(
            new SetupChecker(),
            $this->createMock(PortPoolRepository::class),
            $this->createMock(PortBlockRepository::class),
            $this->createMock(PortLeaseManager::class),
            $resolver,
            $sharedStorageLocator ?? $this->createMock(SharedStorageTemplateLocatorInterface::class),
            $this->createMock(EntityManagerInterface::class),
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
