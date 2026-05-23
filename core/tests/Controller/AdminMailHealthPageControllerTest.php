<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminMailHealthPageController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminMailHealthPageControllerTest extends TestCase
{
    private function translator(): TranslatorInterface
    {
        $t = $this->createMock(TranslatorInterface::class);
        $t->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        return $t;
    }

    public function testAdminCanOpenHealthPage(): void
    {
        $twig = new Environment(new ArrayLoader([
            'admin/mail-system/health.html.twig' => 'Endpoint: {{ healthEndpoint }}',
        ]));
        $controller = new AdminMailHealthPageController($twig, $this->translator());

        $request = new Request();
        $request->attributes->set('current_user', new User('admin@example.com', UserType::Superadmin));

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/api/v1/admin/mail/nodes/health', $response->getContent() ?: '');
        self::assertStringContainsString('/api/v1/admin/mail/nodes', $response->getContent() ?: '');
    }

    public function testNonAdminGetsForbidden(): void
    {
        $twig = new Environment(new ArrayLoader(['admin/mail-system/health.html.twig' => 'ok']));
        $controller = new AdminMailHealthPageController($twig, $this->translator());
        $request = new Request();
        $request->attributes->set('current_user', new User('customer@example.com', UserType::Customer));

        $response = $controller->index($request);

        self::assertSame(403, $response->getStatusCode());
    }
}
