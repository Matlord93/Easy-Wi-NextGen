<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\CmsEvent;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\CmsPost;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\TeamMember;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCmsModuleRoutesTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testBlogRouteWorksWithoutCmsPageEntry(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSiteWithModules();

        $client->request('GET', '/blog');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Release Notes', (string) $client->getResponse()->getContent());

        $client->request('GET', '/blog/release-notes');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Update content', (string) $client->getResponse()->getContent());
    }

    public function testModuleRoutesWorkIndependentFromCmsPagesOverview(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSiteWithModules();

        $client->request('GET', '/events');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Launch Party', (string) $client->getResponse()->getContent());

        $client->request('GET', '/teams');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Alice', (string) $client->getResponse()->getContent());

        $client->request('GET', '/media');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/uploads/cms/hero.jpg', (string) $client->getResponse()->getContent());
    }

    private function seedSiteWithModules(): void
    {
        self::bootKernel();
        $this->ensureInstallLock();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);

        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM media_assets');
        $conn->executeStatement('DELETE FROM team_members');
        $conn->executeStatement('DELETE FROM cms_events');
        $conn->executeStatement('DELETE FROM blog_post_tags');
        $conn->executeStatement('DELETE FROM cms_posts');
        $conn->executeStatement('DELETE FROM blog_tags');
        $conn->executeStatement('DELETE FROM blog_categories');
        $conn->executeStatement('DELETE FROM cms_blocks');
        $conn->executeStatement('DELETE FROM cms_pages');
        $conn->executeStatement('DELETE FROM cms_site_settings');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'localhost');
        $settings = new CmsSiteSettings($site);
        $settings->setModuleTogglesJson([
            'blog' => true,
            'events' => true,
            'team' => true,
            'media' => true,
            'forum' => true,
            'gameserver' => true,
        ]);

        $homepage = new CmsPage($site, 'Startseite', 'startseite', true);
        $blogPost = new CmsPost($site, 'Release Notes', 'release-notes', 'Update content', 'Excerpt', true);

        $event = new CmsEvent($site, 'Launch Party', 'launch-party', 'Event body', new \DateTimeImmutable('+2 days'));
        $event->setPublished(true);

        $member = new TeamMember($site, 'Alice', 'Captain');
        $member->setBio('Core team lead');

        $media = new MediaAsset('/uploads/cms/hero.jpg');
        $media->setSite($site);
        $media->setMime('image/jpeg');
        $media->setTitle('Hero');

        $em->persist($site);
        $em->persist($settings);
        $em->persist($homepage);
        $em->persist($blogPost);
        $em->persist($event);
        $em->persist($member);
        $em->persist($media);
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
