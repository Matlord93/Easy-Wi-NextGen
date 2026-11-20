<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class CmsPagePreviewTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    public static function themes(): iterable
    {
        foreach (ThemeResolver::SUPPORTED_THEMES as $theme) {
            yield $theme => [$theme];
        }
    }

    #[DataProvider('themes')]
    public function testAdminCanPreviewSavedDraftsWithoutChangingTheWebsite(string $theme): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $id = $this->draft();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $before = $em->getConnection()->fetchAllAssociative('SELECT * FROM cms_pages');
        $blocksBefore = $em->getConnection()->fetchAllAssociative('SELECT * FROM cms_blocks');
        $settingsBefore = $em->getConnection()->fetchAllAssociative('SELECT * FROM cms_site_settings');
        $base = '/admin/cms/pages/'.$id.'/preview';
        $view = $client->request('GET', $base.'?theme='.$theme.'&scheme=light&viewport=mobile');
        self::assertResponseIsSuccessful();
        self::assertCount(10, $view->filterXPath('//select[@name="theme"]/option'));
        self::assertSame('', $view->filterXPath('//iframe')->attr('sandbox'));
        self::assertStringContainsString('width:390px', $view->filterXPath('//iframe')->attr('style'));
        $view = $client->request('GET', $base.'/content?theme='.$theme.'&scheme=light');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('SAVED-DRAFT-CONTENT', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('admin+forum@example.test', (string) $client->getResponse()->getContent());
        self::assertCount(1, $view->filterXPath('//body[@inert and @data-cms-preview="true"]'));
        self::assertCount(0, $view->filterXPath('//script | //a[@href] | //form[@action] | //*[@onclick] | //iframe'));
        self::assertCount(0, $view->filterXPath('//html[contains(concat(" ",@class," ")," dark ")]'));
        self::assertStringContainsString('sandbox;', $client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', $client->getResponse()->headers->get('X-Robots-Tag'));
        $view = $client->request('GET', $base.'/content?theme='.$theme.'&scheme=dark');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//html[contains(concat(" ",@class," ")," dark ")]'));
        $client->request('GET', '/draft-preview');
        self::assertResponseStatusCodeSame(404);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($before, $em->getConnection()->fetchAllAssociative('SELECT * FROM cms_pages'));
        self::assertSame($blocksBefore, $em->getConnection()->fetchAllAssociative('SELECT * FROM cms_blocks'));
        self::assertSame($settingsBefore, $em->getConnection()->fetchAllAssociative('SELECT * FROM cms_site_settings'));
    }

    public function testPreviewRejectsForeignPagesAndInvalidSelectionsAndDoesNotAcceptPosts(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $id = $this->draft();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $other = new Site('Other preview', 'other-preview.example.test');
        $foreign = new CmsPage($other, 'FOREIGN-PREVIEW', 'draft-preview', false);
        $em->persist($other);
        $em->persist($foreign);
        $em->flush();
        foreach (['', '/content'] as $suffix) {
            $base = '/admin/cms/pages/'.$id.'/preview'.$suffix;
            $client->request('GET', '/admin/cms/pages/'.$foreign->getId().'/preview'.$suffix);
            self::assertResponseStatusCodeSame(404);
            foreach (['theme=unknown', 'scheme=auto', 'viewport=invalid'] as $query) {
                $client->request('GET', 'http://localhost'.$base.'?'.$query);
                self::assertResponseStatusCodeSame(400);
            }
            $client->request('POST', 'http://localhost'.$base, ['is_published' => '1']);
            self::assertResponseStatusCodeSame(405);
        }
    }

    public function testCustomerCannotAccessAdminPreview(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'customer');
        $id = $this->draft();
        foreach (['', '/content'] as $suffix) {
            $client->request('GET', '/admin/cms/pages/'.$id.'/preview'.$suffix);
            // The admin session guard rejects member/customer sessions before dispatch.
            self::assertResponseStatusCodeSame(401);
        }
    }

    public function testAnonymousCannotAccessSavedDraftPreviews(): void
    {
        $client = self::createClient();
        $id = $this->draft();
        foreach (['', '/content'] as $suffix) {
            $client->request('GET', '/admin/cms/pages/'.$id.'/preview'.$suffix);
            self::assertResponseStatusCodeSame(401);
            self::assertStringNotContainsString('SAVED-DRAFT-CONTENT', (string) $client->getResponse()->getContent());
        }
    }

    public function testModuleAddressDoesNotGetReplacedByACmsPagePreview(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $page = new CmsPage($site, 'CMS-MODULE-DRAFT', 'events', false);
        $em->persist($page);
        $em->flush();
        $view = $client->request('GET', '/admin/cms/pages/'.$page->getId().'/preview');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@role="note"]'));
        $client->request('GET', '/events');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('CMS-MODULE-DRAFT', (string) $client->getResponse()->getContent());
    }

    private function draft(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $settings = $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $site]);
        $settings->setActiveTheme('hosting');
        $page = new CmsPage($site, 'Draft preview', 'draft-preview', false);
        $block = new CmsBlock($page, 'rich_text', '', 10);
        $block->setVersion(2);
        $block->setPayloadJson(['content' => '<p>SAVED-DRAFT-CONTENT</p><script>window.previewExecuted=true</script><form action="/logout" method="post"><button>Write</button></form><a href="/logout" onclick="alert(1)">Logout</a>']);
        $em->persist($page);
        $em->persist($block);
        $em->flush();

        return $page->getId();
    }
}
