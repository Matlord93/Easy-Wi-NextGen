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

final class AdminMailMatrixPageControllerTest extends TestCase
{
    public function testAdminCanOpenMatrixPageAndSeeFetchUrls(): void
    {
        $twig = new Environment(new ArrayLoader([
            'admin/mail-system/matrix.html.twig' => 'H={{ healthEndpoint }} M={{ metricsEndpoint }}',
        ]));
        $controller = new AdminMailHealthPageController($twig);

        $request = new Request();
        $request->attributes->set('current_user', new User('admin@example.com', UserType::Superadmin));

        $response = $controller->matrix($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('/api/v1/admin/mail/nodes/health', $response->getContent() ?: '');
        self::assertStringContainsString('/api/v1/admin/mail/nodes', $response->getContent() ?: '');
        self::assertStringContainsString('/api/v1/admin/mail/nodes/metrics', $response->getContent() ?: '');
    }

    public function testNonAdminGetsForbidden(): void
    {
        $twig = new Environment(new ArrayLoader(['admin/mail-system/matrix.html.twig' => 'ok']));
        $controller = new AdminMailHealthPageController($twig);
        $request = new Request();
        $request->attributes->set('current_user', new User('customer@example.com', UserType::Customer));

        $response = $controller->matrix($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testTemplateDoesNotContainContentFields(): void
    {
        $template = file_get_contents(__DIR__ . '/../../templates/admin/mail-system/matrix.html.twig') ?: '';
        foreach (['subject', 'body', 'from', 'to', 'recipient', 'sender'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($template));
        }
    }
}
