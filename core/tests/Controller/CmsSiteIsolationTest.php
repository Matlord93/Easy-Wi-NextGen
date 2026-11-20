<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\Application\HomepageStatsProvider;
use App\Module\Core\Domain\Entity\CmsEvent;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\ForumBoard;
use App\Module\Core\Domain\Entity\ForumCategory;
use App\Module\Core\Domain\Entity\ForumPost;
use App\Module\Core\Domain\Entity\ForumPostReport;
use App\Module\Core\Domain\Entity\ForumThread;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\TeamGroup;
use App\Module\Core\Domain\Entity\TeamMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CmsSiteIsolationTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    public function testAdminCannotReadChangeOrDeleteAnotherWebsitesContent(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $foreign = $this->fixtures();
        foreach (['events', 'team', 'media', 'forum'] as $section) {
            $client->request('GET', '/admin/cms/'.$section);
            self::assertResponseIsSuccessful($section);
            self::assertStringNotContainsString('FOREIGN-CONTENT', (string) $client->getResponse()->getContent());
        }
        foreach ([['events', $foreign['event']], ['team', $foreign['member']]] as [$section, $id]) {
            $client->request('GET', '/admin/cms/'.$section.'/'.$id.'/edit');
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/admin/cms/'.$section.'/'.$id, ['title' => 'CHANGED', 'name' => 'CHANGED']);
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/admin/cms/'.$section.'/'.$id.'/delete');
            self::assertResponseStatusCodeSame(404);
        }
        foreach (['team/groups/'.$foreign['group'], 'team/groups/'.$foreign['group'].'/delete', 'media/'.$foreign['asset'].'/delete'] as $url) {
            $client->request('POST', '/admin/cms/'.$url, ['name' => 'CHANGED']);
            self::assertResponseStatusCodeSame(404);
        }
        // A valid token must not permit moderation on a different website.
        $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('admin_forum_report_resolve_'.$foreign['report'])->getValue();
        $client->request('POST', '/admin/cms/forum/reports/'.$foreign['report'].'/resolve', ['_token' => $token, 'delete_post' => '1']);
        self::assertResponseStatusCodeSame(404);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('FOREIGN-CONTENT', $em->find(CmsEvent::class, $foreign['event'])->getTitle());
        self::assertSame('FOREIGN-CONTENT', $em->find(TeamMember::class, $foreign['member'])->getName());
        self::assertSame('FOREIGN-CONTENT', $em->find(TeamGroup::class, $foreign['group'])->getName());
        self::assertNotNull($em->find(MediaAsset::class, $foreign['asset']));
        self::assertSame('open', $em->find(ForumPostReport::class, $foreign['report'])->getStatus());
        self::assertFalse($em->find(ForumPost::class, $foreign['post'])->isDeleted());
    }

    public function testUnknownHostCannotFallBackToAnArbitraryWebsite(): void
    {
        $client = self::createClient();
        // Single-site installations retain their existing aliases.
        $client->request('GET', 'http://alias.example.test/');
        self::assertResponseIsSuccessful();
        $this->fixtures();
        foreach (['/', '/startseite', '/blog', '/events', '/teams', '/media', '/forum', '/kontakt', '/cookies', '/servers', '/sitemap.xml', '/login', '/register'] as $path) {
            $client->request('GET', 'http://unknown.example.test'.$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $client->request('GET', 'http://other.example.test/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('OTHER-HOMEPAGE', (string) $client->getResponse()->getContent());
    }

    public function testAdminCanStillEditAndDeleteContentOfTheCurrentWebsite(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $this->fixtures();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $event = new CmsEvent($site, 'Own event', 'own-event', 'Description', new \DateTimeImmutable('+1 day'));
        $member = new TeamMember($site, 'Own member', 'Captain');
        $group = new TeamGroup($site, 'Own group', 'Game', 'own-group');
        foreach ([$event, $member, $group] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        foreach ([['events', $event->getId(), CmsEvent::class], ['team', $member->getId(), TeamMember::class], ['team/groups', $group->getId(), TeamGroup::class]] as [$section, $id, $class]) {
            if ('team/groups' !== $section) {
                $client->request('GET', '/admin/cms/'.$section.'/'.$id.'/edit');
                self::assertResponseIsSuccessful();
            }
            $client->request('POST', '/admin/cms/'.$section.'/'.$id, ['title' => 'Updated', 'name' => 'Updated', 'role_title' => 'Captain', 'slug' => 'updated', 'start_at' => '2030-01-01 12:00:00']);
            self::assertResponseStatusCodeSame(302);
            $updated = self::getContainer()->get(EntityManagerInterface::class)->find($class, $id);
            self::assertSame('Updated', $updated instanceof CmsEvent ? $updated->getTitle() : $updated->getName());
            $client->request('POST', '/admin/cms/'.$section.'/'.$id.'/delete');
            self::assertResponseStatusCodeSame(302);
            self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->find($class, $id));
        }
    }

    public function testDisabledModulesDoNotSupplyHomepageDataAndHiddenBoardsStayHidden(): void
    {
        $client = self::createClient();
        $this->fixtures();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'other.example.test']);
        $settings = $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $site]);
        $settings->setModuleTogglesJson(['team' => false, 'events' => false, 'forum' => false, 'gameserver' => false]);
        $em->flush();
        $vars = self::getContainer()->get(HomepageStatsProvider::class)->getTemplateVars($site);
        foreach (['cms_recent_events', 'cms_server_status', 'cms_activity', 'cms_top_members', 'cms_team_groups'] as $key) {
            self::assertSame([], $vars[$key]);
        }
        foreach (['members_count', 'events_count', 'servers_count', 'servers_online', 'forum_posts'] as $key) {
            self::assertNull($vars['cms_home_stats'][$key]);
        }
        $settings->setModuleTogglesJson(['forum' => true]);
        $board = $em->getRepository(ForumBoard::class)->findOneBy(['site' => $site]);
        $board->setActive(false);
        $em->flush();
        $client->request('GET', 'http://other.example.test/');
        self::assertResponseIsSuccessful();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'other.example.test']);
        $vars = self::getContainer()->get(HomepageStatsProvider::class)->getTemplateVars($site);
        self::assertSame([], $vars['cms_activity']);
        self::assertSame(0, $vars['cms_home_stats']['forum_posts']);
    }

    public function testUploadPreservesMetadataAndUsesTheCurrentWebsitesDirectory(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $siteId = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost'])->getId();
        $temporary = tempnam(sys_get_temp_dir(), 'cms-upload-');
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGQAAAABJRU5ErkJggg==', true);
        file_put_contents($temporary, $bytes);
        $path = null;
        try {
            $view = $client->request('GET', '/admin/cms/media');
            $uploadToken = $view->filterXPath('//form[@data-media-upload]//input[@name="_token"]')->attr('value');
            $client->request('POST', '/admin/cms/media', ['title' => 'OWNED-UPLOAD', '_token' => $uploadToken], ['file' => new UploadedFile($temporary, 'image.png', 'image/png', test: true)]);
            self::assertResponseRedirects('/admin/cms/media');
            $asset = self::getContainer()->get(EntityManagerInterface::class)->getRepository(MediaAsset::class)->findOneBy(['title' => 'OWNED-UPLOAD']);
            self::assertNotNull($asset);
            $path = dirname(__DIR__, 2).'/public'.$asset->getPath();
            self::assertStringStartsWith('/uploads/cms/'.$siteId.'/', $asset->getPath());
            self::assertSame($siteId, $asset->getSite()->getId());
            self::assertSame('image/png', $asset->getMime());
            self::assertSame(strlen($bytes), $asset->getSize());
            self::assertFileExists($path);
            $view = $client->request('GET', '/admin/cms/media');
            $deleteToken = $view->filterXPath('//tr[@data-media-id="'.$asset->getId().'"]//input[@name="_token"]')->attr('value');
            $client->request('POST', '/admin/cms/media/'.$asset->getId().'/delete', ['_token' => $deleteToken]);
            self::assertResponseRedirects('/admin/cms/media');
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            if (null !== $path && is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @return array<string,int> */
    private function fixtures(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = new Site('Other website', 'other.example.test');
        $settings = new CmsSiteSettings($site);
        $settings->setActiveTheme('clan');
        $page = new CmsPage($site, 'OTHER-HOMEPAGE', 'startseite', true);
        $event = new CmsEvent($site, 'FOREIGN-CONTENT', 'foreign-event', 'Foreign description', new \DateTimeImmutable('+1 day'));
        $event->setPublished(true);
        $member = new TeamMember($site, 'FOREIGN-CONTENT', 'Captain');
        $group = new TeamGroup($site, 'FOREIGN-CONTENT', 'Game', 'foreign-team');
        $asset = new MediaAsset('/uploads/foreign-placeholder.png');
        $asset->setSite($site);
        $asset->setTitle('FOREIGN-CONTENT');
        $category = new ForumCategory($site, 'Foreign category', 'foreign-category');
        $board = new ForumBoard($site, $category, 'Foreign board', 'foreign-board');
        $thread = new ForumThread($site, $board, null, 'FOREIGN-CONTENT', 'foreign-thread');
        $post = new ForumPost($site, $thread, null, 'FOREIGN-CONTENT');
        $report = new ForumPostReport($post, null, 'FOREIGN-CONTENT');
        foreach ([$site, $settings, $page, $event, $member, $group, $asset, $category, $board, $thread, $post, $report] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return ['event' => $event->getId(), 'member' => $member->getId(), 'group' => $group->getId(), 'asset' => $asset->getId(), 'report' => $report->getId(), 'post' => $post->getId()];
    }
}
