<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\UI\Controller\Admin\AdminCmsPageController;
use App\Module\Cms\UI\Controller\Public\PublicCmsPageController;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class CmsPageBlocksV2Test extends KernelTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testRenderLegacyBlockStillWorks(): void
    {
        self::bootKernel();
        $this->ensureInstallerLocked();
        $this->seedPage('legacy-page', static function (CmsPage $page): array {
            return [new CmsBlock($page, 'text', '<p>Legacy Block Content</p>', 1)];
        });

        /** @var PublicCmsPageController $controller */
        $controller = self::getContainer()->get(PublicCmsPageController::class);
        $response = $controller->show(Request::create('http://demo.local/legacy-page', 'GET'), 'legacy-page');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Legacy Block Content', (string) $response->getContent());
    }

    public function testRenderV2HeroBlockWorks(): void
    {
        self::bootKernel();
        $this->ensureInstallerLocked();
        $this->seedPage('hero-page', static function (CmsPage $page): array {
            $block = new CmsBlock($page, 'hero', '', 1);
            $block->setVersion(2);
            $block->setPayloadJson([
                'headline' => 'V2 Hero Headline',
                'subheadline' => 'V2 Subheadline',
                'backgroundImagePath' => '/uploads/hero.jpg',
                'ctaText' => 'Jetzt starten',
                'ctaUrl' => '/start',
            ]);

            return [$block];
        });

        /** @var PublicCmsPageController $controller */
        $controller = self::getContainer()->get(PublicCmsPageController::class);
        $response = $controller->show(Request::create('http://demo.local/hero-page', 'GET'), 'hero-page');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('V2 Hero Headline', (string) $response->getContent());
        self::assertStringContainsString('Jetzt starten', (string) $response->getContent());
    }

    public function testAdminSaveV2BlockPersistsPayloadAndVersion(): void
    {
        self::bootKernel();
        $this->ensureInstallerLocked();
        $pageId = $this->seedPage('admin-page', static fn (CmsPage $page): array => []);

        /** @var AdminCmsPageController $controller */
        $controller = self::getContainer()->get(AdminCmsPageController::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User('admin-step8@example.test', UserType::Admin);
        $admin->setPasswordHash('test-hash');
        $em->persist($admin);
        $em->flush();

        $request = Request::create('http://demo.local/admin/cms/pages/' . $pageId . '/blocks', 'POST', [
            'type' => 'hero',
            'version' => '2',
            'hero_headline' => 'Persisted Headline',
            'hero_subheadline' => 'Persisted Subheadline',
            'hero_background_image_path' => '/uploads/persisted.jpg',
            'hero_cta_text' => 'Go',
            'hero_cta_url' => '/go',
        ]);
        $request->attributes->set('current_user', $admin);

        $response = $controller->createBlock($request, $pageId);
        self::assertSame(200, $response->getStatusCode());

        $row = $em->getConnection()->fetchAssociative('SELECT version, payload_json FROM cms_blocks WHERE page_id = ? ORDER BY id DESC LIMIT 1', [$pageId]);
        self::assertSame(2, (int) ($row['version'] ?? 0));
        self::assertIsString($row['payload_json'] ?? null);
        self::assertStringContainsString('Persisted Headline', (string) $row['payload_json']);
    }

    public function testMixedLegacyAndV2BlocksRender200(): void
    {
        self::bootKernel();
        $this->ensureInstallerLocked();
        $this->seedPage('mixed-page', static function (CmsPage $page): array {
            $legacy = new CmsBlock($page, 'text', '<p>Legacy Mixed</p>', 1);
            $hero = new CmsBlock($page, 'hero', '', 2);
            $hero->setVersion(2);
            $hero->setPayloadJson([
                'headline' => 'Mixed Hero',
                'subheadline' => '',
                'backgroundImagePath' => '',
                'ctaText' => '',
                'ctaUrl' => '',
            ]);

            return [$legacy, $hero];
        });

        /** @var PublicCmsPageController $controller */
        $controller = self::getContainer()->get(PublicCmsPageController::class);
        $response = $controller->show(Request::create('http://demo.local/mixed-page', 'GET'), 'mixed-page');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Legacy Mixed', (string) $response->getContent());
        self::assertStringContainsString('Mixed Hero', (string) $response->getContent());
    }

    /**
     * @param callable(CmsPage): array<int, CmsBlock> $buildBlocks
     */
    private function seedPage(string $slug, callable $buildBlocks): int
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        $conn = $em->getConnection();

        $conn->executeStatement('DELETE FROM cms_blocks');
        $conn->executeStatement('DELETE FROM cms_pages');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'demo.local');
        $page = new CmsPage($site, 'Page ' . $slug, $slug, true);

        $em->persist($site);
        $em->persist($page);

        foreach ($buildBlocks($page) as $block) {
            $page->addBlock($block);
            $em->persist($block);
        }

        $em->flush();

        return (int) $page->getId();
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
