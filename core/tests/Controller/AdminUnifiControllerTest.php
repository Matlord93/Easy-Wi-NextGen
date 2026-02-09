<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Unifi\Application\UnifiApiClient;
use App\Module\Unifi\Application\UnifiPortSyncService;
use App\Module\Unifi\Domain\Entity\UnifiSettings;
use App\Module\Unifi\Infrastructure\Repository\UnifiAuditLogRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiManualRuleRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiPolicyRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiSettingsRepository;
use App\Module\Unifi\UI\Controller\Admin\AdminUnifiController;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Twig\Environment;

final class AdminUnifiControllerTest extends TestCase
{
    public function testSyncIsBlockedWhenModuleDisabled(): void
    {
        $settings = new UnifiSettings();
        $settings->setEnabled(false);

        $settingsRepository = $this->createMock(UnifiSettingsRepository::class);
        $settingsRepository->method('getSettings')->willReturn($settings);

        $syncService = $this->createMock(UnifiPortSyncService::class);
        $syncService->expects($this->never())->method('sync');

        $controller = new AdminUnifiController(
            $settingsRepository,
            $this->createMock(UnifiPolicyRepository::class),
            $this->createMock(UnifiManualRuleRepository::class),
            $this->createMock(UnifiAuditLogRepository::class),
            new AgentRepository($this->createMock(ManagerRegistry::class)),
            new EncryptionService(null, null),
            new UnifiApiClient(new MockHttpClient()),
            $syncService,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(Environment::class),
        );

        $admin = new User('admin@example.test', UserType::Admin);
        $request = Request::create('/admin/unifi/auto-preview/sync', 'POST');
        $request->attributes->set('current_user', $admin);

        $response = $controller->syncPreview($request);

        self::assertSame(400, $response->getStatusCode());
    }
}
