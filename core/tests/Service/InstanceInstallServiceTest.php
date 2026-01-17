<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Ports\Application\PortLeaseManager;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Module\Gameserver\Application\InstanceInstallService;
use App\Module\Core\Application\SetupChecker;
use App\Module\Gameserver\Application\TemplateInstallResolver;
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

    private function buildService(): InstanceInstallService
    {
        return new InstanceInstallService(
            new SetupChecker(),
            $this->createMock(PortPoolRepository::class),
            $this->createMock(PortBlockRepository::class),
            $this->createMock(PortLeaseManager::class),
            $this->createMock(TemplateInstallResolver::class),
            $this->createMock(EntityManagerInterface::class),
        );
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
