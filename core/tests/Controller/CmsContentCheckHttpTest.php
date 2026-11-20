<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\Application\CmsContentCheck;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

final class CmsContentCheckHttpTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    public function testChecksSavedContentWithoutPublishingOrCallingTargets(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $page = $this->fixture();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $before = $this->snapshot($em);
        $view = $client->request('GET', '/admin/cms/pages/'.$page.'/checks');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//*[@id="cms-content-checks"]'));
        foreach (['page_missing' => '/foreign-only', 'page_draft' => '/draft-only', 'module_disabled' => '/events', 'media_missing' => '/uploads/cms-check-missing.png', 'invalid_url' => 'alert(1)'] as $code => $target) {
            self::assertStringContainsString($target, $view->filterXPath('//tr[@data-cms-check="'.$code.'"]')->text());
        }
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('FOREIGN-PRIVATE-TITLE', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('secret-query-value', (string) $client->getResponse()->getContent());
        self::assertCount(0, $view->filterXPath('//script | //img | //iframe | //a[@href="/logout"]'));
        self::assertCount(5, $view->filterXPath('//button[@hx-get and @data-panel-reveal="create"]'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($before, $this->snapshot($em));
        // /logout was present as a saved link but must never be requested.
        $client->request('GET', '/admin/cms/pages/'.$page);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="cms-content-checks"]'));
        self::assertSame("cms-blocks-changed[event.target.getAttribute('hx-target') != '#cms-block-form'] from:body", $client->getCrawler()->filterXPath('//*[@id="cms-block-form"]')->attr('hx-trigger'));
        $client->request('GET', '/admin/cms/pages/'.$page.'/preview');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $client->getCrawler()->filterXPath('//*[@id="cms-content-checks"]'));
        $client->request('GET', '/check-source');
        self::assertResponseStatusCodeSame(404);
    }

    public function testReportsOnlyCurrentWebsiteReferencesAndProtectsTheCheckEndpoint(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $page = $this->fixture();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $foreign = $em->getRepository(CmsPage::class)->findOneBy(['slug' => 'foreign-only']);
        $client->request('GET', '/admin/cms/pages/'.$foreign->getId().'/checks');
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/admin/cms/pages/'.$page.'/checks');
        self::assertResponseStatusCodeSame(405);
        $client->getCookieJar()->clear();
        $client->request('GET', '/admin/cms/pages/'.$page.'/checks');
        self::assertResponseStatusCodeSame(401);
        $this->loginAsRole($client, 'customer');
        $client->request('GET', '/admin/cms/pages/'.$page.'/checks');
        self::assertResponseStatusCodeSame(401);
    }

    public function testResolvedPagesAndFilesClearNoticesOnTheNextRead(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $pageId = $this->fixture();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->find(CmsPage::class, $pageId);
        $site = $page->getSite();
        $draft = $em->getRepository(CmsPage::class)->findOneBy(['site' => $site, 'slug' => 'draft-only']);
        $draft->setPublished(true);
        $em->persist(new CmsPage($site, 'Own fixed target', 'foreign-only', true));
        $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $site])->setModuleTogglesJson(['events' => true]);
        $em->flush();
        $view = $client->request('GET', '/admin/cms/pages/'.$pageId.'/checks');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $view->filterXPath('//tr[@data-cms-check="page_draft" or @data-cms-check="module_disabled"]'));
        self::assertStringNotContainsString('/foreign-only', $view->filterXPath('//*[@id="cms-content-checks"]')->text());
    }

    public function testFilesStayInsideTheirPublicRootAndRepeatedReferencesShareChecks(): void
    {
        $client = self::createClient();
        $pageId = $this->fixture();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->find(CmsPage::class, $pageId);
        $directory = dirname(__DIR__, 2).'/public/uploads/cms-check-'.getmypid();
        mkdir($directory, 0775, true);
        $private = tempnam(sys_get_temp_dir(), 'cms-private-');
        $mediaUrl = '/uploads/cms-check-'.getmypid().'/own image.txt';
        file_put_contents($directory.'/own image.txt', 'Local public file');
        file_put_contents($private, 'PRIVATE-FILE-CONTENT');
        symlink($private, $directory.'/escape.txt');
        try {
            $block = new CmsBlock($page, 'text', '<a href="./owned?lang=de">Owned</a><a href="../owned">Relative</a><a href="http://LOCALHOST/owned">Absolute</a><img src="'.$mediaUrl.'"><img src="'.$mediaUrl.'"><img src="/uploads/cms-check-'.getmypid().'/escape.txt"><a href="/blog/a-detail">Module detail</a><img src="/dynamic-image/123">', 20);
            $em->persist($block);
            $em->flush();
            // Inspection must reflect saved publication state, not pending entity edits.
            $em->getRepository(CmsPage::class)->findOneBy(['site' => $page->getSite(), 'slug' => 'owned'])->setPublished(false);
            $report = self::getContainer()->get(CmsContentCheck::class)->inspect($page, [$block]);
            $blockIssues = array_values(array_filter($report['issues'], static fn (array $issue): bool => 'block' === $issue['source']));
            self::assertCount(1, $blockIssues);
            self::assertSame('media_missing', $blockIssues[0]['code']);
            self::assertStringEndsWith('/escape.txt', $blockIssues[0]['target']);
            self::assertSame(2, $report['skipped']);
            self::assertSame('PRIVATE-FILE-CONTENT', file_get_contents($private));
        } finally {
            unlink($directory.'/escape.txt');
            unlink($directory.'/own image.txt');
            rmdir($directory);
            unlink($private);
        }
    }

    private function fixture(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $settings = $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $site]);
        $settings->setActiveTheme('hosting');
        $settings->setModuleTogglesJson(['events' => false]);
        $settings->setHeaderLinksJson([['label' => 'Own page', 'url' => '/owned', 'external' => false]]);
        $settings->setFooterLinksJson([['label' => 'Own page', 'url' => '/owned', 'external' => false]]);
        $page = new CmsPage($site, 'Saved draft', 'check-source', false);
        $owned = new CmsPage($site, 'Owned target', 'owned', true);
        $draft = new CmsPage($site, 'Draft target', 'draft-only', false);
        $other = new Site('Other website', 'other-check.example.test');
        $foreign = new CmsPage($other, 'FOREIGN-PRIVATE-TITLE', 'foreign-only', true);
        $block = new CmsBlock($page, 'rich_text', '', 10);
        $block->setVersion(2);
        $block->setPayloadJson(['content' => '<a href="/foreign-only?token=secret-query-value">Missing here</a><a href="/draft-only">Draft</a><a href="/events">Disabled</a><a href="/owned">OK</a><a href="javascript:alert(1)">Invalid</a><a href="https://external.invalid/">External</a><a href="sftp://backup.example.test/path">Custom protocol</a><a href="#local-anchor">Anchor</a><a href="/logout">Action</a><img src="/uploads/cms-check-missing.png">']);
        foreach ([$page, $owned, $draft, $other, $foreign, $block] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return $page->getId();
    }

    private function snapshot(EntityManagerInterface $em): array
    {
        $data = [];
        foreach (['cms_pages', 'cms_blocks', 'cms_site_settings'] as $table) {
            $data[$table] = $em->getConnection()->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return $data;
    }
}
