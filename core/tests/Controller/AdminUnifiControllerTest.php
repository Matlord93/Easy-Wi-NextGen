<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Unifi\Application\UnifiApiClient;
use App\Module\Unifi\Application\UnifiSynchronizerInterface;
use App\Module\Unifi\Domain\Entity\UnifiSettings;
use App\Module\Unifi\Infrastructure\Repository\UnifiAuditLogRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiManualRuleRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiPolicyRepository;
use App\Module\Unifi\Application\UnifiSettingsProviderInterface;
use App\Module\Unifi\UI\Controller\Admin\AdminUnifiController;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AdminUnifiControllerTest extends TestCase
{
    public function testSyncIsBlockedWhenModuleDisabled(): void
    {
        $settings = new UnifiSettings();
        $settings->setEnabled(false);

        $settingsRepository = $this->createStub(UnifiSettingsProviderInterface::class);
        $settingsRepository->method('getSettings')->willReturn($settings);

        $syncService = $this->createMock(UnifiSynchronizerInterface::class);
        $syncService->expects($this->never())->method('sync');

        $controller = new AdminUnifiController(
            $settingsRepository,
            new UnifiPolicyRepository($this->createStub(ManagerRegistry::class)),
            new UnifiManualRuleRepository($this->createStub(ManagerRegistry::class)),
            new UnifiAuditLogRepository($this->createStub(ManagerRegistry::class)),
            new AgentRepository($this->createStub(ManagerRegistry::class)),
            new EncryptionService(null, null),
            new UnifiApiClient(new MockHttpClient()),
            $syncService,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(Environment::class),
            $this->createStub(TranslatorInterface::class),
        );

        $admin = new User('admin@example.test', UserType::Admin);
        $request = Request::create('/admin/unifi/auto-preview/sync', 'POST');
        $request->attributes->set('current_user', $admin);

        $response = $controller->syncPreview($request);

        self::assertSame(400, $response->getStatusCode());
    }
}
