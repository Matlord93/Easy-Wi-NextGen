<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\UI\Controller\Admin\AdminCmsSettingsController;
use App\Module\Cms\UI\Controller\Public\PublicCmsPageController;
use App\Module\Cms\UI\Controller\Public\ThemePreviewController;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class Phase11RegressionSmokeTest extends KernelTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testPublicHomeReturns200(): void
    {
        self::bootKernel();
        $this->ensureInstallerLocked();
        $this->seedPublishedHomePage();

        /** @var PublicCmsPageController $controller */
        $controller = self::getContainer()->get(PublicCmsPageController::class);
        $response = $controller->home(Request::create('http://demo.local/', 'GET'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAdminCmsSettingsAccessMatrix(): void
    {
        self::bootKernel();
        $this->seedPublishedHomePage();

        /** @var AdminCmsSettingsController $controller */
        $controller = self::getContainer()->get(AdminCmsSettingsController::class);

        $anonymousResponse = $controller->index(Request::create('http://demo.local/admin/cms/settings', 'GET'));
        self::assertSame(403, $anonymousResponse->getStatusCode());

        $admin = new User('admin-smoke@example.test', UserType::Admin);
        $admin->setPasswordHash('test-hash');
        $request = Request::create('http://demo.local/admin/cms/settings', 'GET');
        $request->attributes->set('current_user', $admin);

        $adminResponse = $controller->index($request);
        self::assertSame(200, $adminResponse->getStatusCode());
    }

    public function testThemePreviewRouteReturns200(): void
    {
        self::bootKernel();

        /** @var ThemePreviewController $controller */
        $controller = self::getContainer()->get(ThemePreviewController::class);
        $response = $controller->__invoke(Request::create('http://demo.local/preview/minimal', 'GET'), 'minimal');

        self::assertSame(200, $response->getStatusCode());
    }

    private function seedPublishedHomePage(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        $conn = $em->getConnection();

        $conn->executeStatement('DELETE FROM cms_pages');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'demo.local');
        $page = new CmsPage($site, 'Startseite', 'startseite', true);

        $em->persist($site);
        $em->persist($page);
        $em->flush();
    }

    private function ensureSchema(EntityManagerInterface $em): void
    {
        if (self::$schemaBootstrapped) {
            return;
        }

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();

        if ($metadata !== []) {
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }

        self::$schemaBootstrapped = true;
    }

    private function ensureInstallerLocked(): void
    {
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $stateDir = $projectDir . '/srv/setup/state';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0775, true);
        }

        file_put_contents($stateDir . '/install.lock', (new \DateTimeImmutable())->format(DATE_ATOM));
    }
}
