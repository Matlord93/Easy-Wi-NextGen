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
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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

    private function buildService(): InstanceInstallService
    {
        return new InstanceInstallService(
            new SetupChecker(),
            $this->createMock(PortPoolRepository::class),
            $this->createMock(PortBlockRepository::class),
            $this->createMock(PortLeaseManager::class),
            $this->createMock(TemplateInstallResolver::class),
            self::buildTemplateRepository(),
            $this->createMock(EntityManagerInterface::class),
        );
    }


    private static function buildTemplateRepository(): TemplateRepository
    {
        $entityManager = new class () implements EntityManagerInterface {
            public function getClassMetadata(string $className): \Doctrine\ORM\Mapping\ClassMetadata
            {
                return new \Doctrine\ORM\Mapping\ClassMetadata($className);
            }

            public function getRepository(string $className): \Doctrine\ORM\EntityRepository
            {
                throw new \BadMethodCallException('Not implemented.');
            }

            public function __call(string $name, array $arguments): mixed
            {
                throw new \BadMethodCallException('Not implemented.');
            }
        };

        $registry = new class ($entityManager) implements ManagerRegistry {
            public function __construct(private readonly EntityManagerInterface $entityManager)
            {
            }

            public function getDefaultConnectionName(): string { throw new \BadMethodCallException('Not implemented.'); }
            public function getConnection(?string $name = null): object { throw new \BadMethodCallException('Not implemented.'); }
            public function getConnections(): array { return []; }
            public function getConnectionNames(): array { return []; }
            public function getDefaultManagerName(): string { return 'default'; }
            public function getManager(?string $name = null): object { return $this->entityManager; }
            public function getManagers(): array { return ['default' => $this->entityManager]; }
            public function resetManager(?string $name = null): object { return $this->entityManager; }
            public function getAliasNamespace(string $alias): string { throw new \BadMethodCallException('Not implemented.'); }
            public function getManagerNames(): array { return ['default' => 'default']; }
            public function getRepository(string $persistentObject, ?string $persistentManagerName = null): object { throw new \BadMethodCallException('Not implemented.'); }
            public function getManagerForClass(string $class): ?object { return $this->entityManager; }
        };

        return new TemplateRepository($registry);
    }

    /**
     * @param array<int, array<string, mixed>> $requirementVars
     * @param string[] $supportedOs
     */
    private function buildInstance(array $requirementVars, array $supportedOs, string $nodeOs): Instance
    {
        $template = new Template(
            'game',
            'Game',
            null,
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'udp'],
            ],
            'start',
            [],
            [],
            [],
            [],
            'install',
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
