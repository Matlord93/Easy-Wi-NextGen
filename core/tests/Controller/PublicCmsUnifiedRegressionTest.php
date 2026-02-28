<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCmsUnifiedRegressionTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testHomepageComesFromNewCms(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedCmsSite();

        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Startseite aus CMS', (string) $client->getResponse()->getContent());
    }

    public function testLegacyNestedCmsEndpointsReturn404(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedCmsSite();

        $client->request('GET', '/blog/legacy-post');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/forum/thread/1-legacy');
        self::assertResponseStatusCodeSame(301);
    }

    public function testHeaderAndFooterUseSiteSettingsLinks(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedCmsSite();

        $client->request('GET', '/');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Discord', $html);
        self::assertStringContainsString('Impressum', $html);
    }

    public function testMaintenanceRendersCustomMessageAndGraphicWithoutSchedule(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedCmsSite();

        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        self::assertInstanceOf(Site::class, $site);

        $site->setMaintenanceEnabled(true);
        $site->setMaintenanceMessage("Erste Zeile\n[b]Wichtig[/b] [url=https://example.com]Status[/url]");
        $site->setMaintenanceGraphicPath('/images/maintenance-custom.png');
        $site->setMaintenanceStartsAt(null);
        $site->setMaintenanceEndsAt(null);
        $em->flush();

        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(503);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Erste Zeile<br>', $html);
        self::assertStringContainsString('<strong>Wichtig</strong>', $html);
        self::assertStringContainsString('<a href="https://example.com" target="_blank" rel="noopener noreferrer">Status</a>', $html);
        self::assertStringContainsString('/images/maintenance-custom.png', $html);
        self::assertSame("default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'", $client->getResponse()->headers->get('Content-Security-Policy'));
    }

    private function seedCmsSite(): void
    {
        self::bootKernel();
        $this->ensureInstallLock();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);

        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM cms_blocks');
        $conn->executeStatement('DELETE FROM cms_pages');
        $conn->executeStatement('DELETE FROM cms_site_settings');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'localhost');
        $page = new CmsPage($site, 'Startseite', 'startseite', true);
        $block = new CmsBlock($page, 'rich_text', '<p>Startseite aus CMS</p>', 10);

        $settings = new CmsSiteSettings($site);
        $settings->setHeaderLinksJson([
            ['label' => 'Discord', 'url' => 'https://discord.gg/example', 'external' => true],
        ]);
        $settings->setFooterLinksJson([
            ['label' => 'Impressum', 'url' => '/impressum', 'external' => false],
        ]);

        $em->persist($site);
        $em->persist($page);
        $em->persist($block);
        $em->persist($settings);
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

    private function ensureInstallLock(): void
    {
        $path = dirname(__DIR__, 2) . '/srv/setup/state/install.lock';
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (!file_exists($path)) {
            file_put_contents($path, (new \DateTimeImmutable())->format(DATE_ATOM));
        }
    }
}
