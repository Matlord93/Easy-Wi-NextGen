<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\UI\Controller\Admin\AdminCmsPageController;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\CmsBlockRepository;
use App\Repository\CmsPageRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class AdminCmsPageControllerAccessTest extends TestCase
{
    public function testAdminCanAccessCmsPagesForm(): void
    {
        $controller = $this->buildController();
        $request = Request::create('/admin/cms/pages/form');
        $request->attributes->set('current_user', new User('admin@example.test', UserType::Admin));

        $response = $controller->form($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testNonAdminCannotAccessCmsPagesForm(): void
    {
        $controller = $this->buildController();
        $request = Request::create('/admin/cms/pages/form');
        $request->attributes->set('current_user', new User('customer@example.test', UserType::Customer));

        $response = $controller->form($request);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAnonymousCannotAccessCmsPagesForm(): void
    {
        $controller = $this->buildController();
        $request = Request::create('/admin/cms/pages/form');

        $response = $controller->form($request);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    private function buildController(): AdminCmsPageController
    {
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturn('ok');

        /** @var CmsPageRepository $pageRepository */
        $pageRepository = $this->newInstanceWithoutConstructor(CmsPageRepository::class);
        /** @var CmsBlockRepository $blockRepository */
        $blockRepository = $this->newInstanceWithoutConstructor(CmsBlockRepository::class);
        /** @var \App\Module\Core\Application\SiteResolver $siteResolver */
        $siteResolver = $this->newInstanceWithoutConstructor(\App\Module\Core\Application\SiteResolver::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->newInstanceWithoutConstructor(\Doctrine\ORM\EntityManager::class);
        /** @var \App\Module\Core\Application\AuditLogger $auditLogger */
        $auditLogger = $this->newInstanceWithoutConstructor(\App\Module\Core\Application\AuditLogger::class);
        return new AdminCmsPageController(
            $pageRepository,
            $blockRepository,
            $siteResolver,
            $entityManager,
            $auditLogger,
            $twig,
        );
    }

    private function newInstanceWithoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
