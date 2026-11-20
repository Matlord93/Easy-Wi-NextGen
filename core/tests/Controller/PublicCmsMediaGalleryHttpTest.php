<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\ModuleSetting;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\MediaAssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class PublicCmsMediaGalleryHttpTest extends AbstractWebTestCase
{
    private string $directory;
    private string $relative;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->relative = '/uploads/gallery-test-'.bin2hex(random_bytes(6));
        $this->directory = dirname(__DIR__, 2).'/public'.$this->relative;
        mkdir($this->directory, 0775, true);
        $file = $this->directory.'/image.png';
        $image = imagecreatetruecolor(800, 400);
        imagepng($image, $file);
        $this->files[] = $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testPaginationFiltersInSqlAndKeepsWebsiteDataSeparate(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $own = $this->site();
        $foreign = new Site('Foreign', 'foreign-gallery.test');
        $em->persist($foreign);
        $ids = [];
        for ($i = 0; $i < 31; ++$i) {
            $ids[] = $this->asset('Gallery '.$i)->getId();
        }
        for ($i = 0; $i < 30; ++$i) {
            $this->asset('PDF-SECRET', mime: 'application/pdf');
        }
        $this->asset('FOREIGN-SECRET', owner: $foreign);
        $unassigned = $this->asset('UNASSIGNED-SECRET');
        $unassigned->setSite(null);
        $this->asset('UNTYPED-SECRET', mime: null);
        $em->flush();
        $rows = self::getContainer()->get(MediaAssetRepository::class)->findGalleryPage($own, '', 1);
        self::assertCount(25, $rows);
        $before = $em->getConnection()->fetchAllAssociative('SELECT * FROM media_assets ORDER BY id');
        $hash = hash_file('sha256', $this->directory.'/image.png');
        $view = $client->request('GET', '/media?lang=en&q=Gallery');
        self::assertResponseIsSuccessful();
        $first = array_map('intval', $view->filterXPath('//figure[@data-media-id]')->extract(['data-media-id']));
        self::assertSame(array_slice(array_reverse($ids), 0, 24), $first);
        self::assertStringContainsString('q=Gallery', $view->filterXPath('//a[@rel="next"]')->attr('href'));
        self::assertStringContainsString('lang=en', $view->filterXPath('//a[@rel="next"]')->attr('href'));
        self::assertCount(24, $view->filterXPath('//figure//img[@loading="lazy" and @decoding="async"]'));
        foreach (['PDF-SECRET', 'FOREIGN-SECRET', 'UNASSIGNED-SECRET', 'UNTYPED-SECRET'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $client->getResponse()->getContent());
        }
        $next = $view->filterXPath('//a[@rel="next"]')->attr('href');
        $view = $client->request('GET', $next);
        self::assertResponseIsSuccessful();
        $second = array_map('intval', $view->filterXPath('//figure[@data-media-id]')->extract(['data-media-id']));
        self::assertCount(7, $second);
        self::assertSame([], array_intersect($first, $second));
        self::assertCount(0, $view->filterXPath('//a[@rel="next"]'));
        self::assertCount(1, $view->filterXPath('//a[@rel="prev"]'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($before, $em->getConnection()->fetchAllAssociative('SELECT * FROM media_assets ORDER BY id'));
        self::assertSame($hash, hash_file('sha256', $this->directory.'/image.png'));
    }

    public function testSearchIsLiteralRetainedTranslatedAndHandlesEmptyPages(): void
    {
        $client = self::createClient();
        $match = $this->asset('Summer 100%_!');
        $this->asset('Summer 100ABC!');
        $view = $client->request('GET', '/media?lang=en&q=100%25_!');
        self::assertResponseIsSuccessful();
        self::assertSame('Search media', $view->filterXPath('//label[@for="cms-media-search"]')->text());
        self::assertSame('100%_!', $view->filterXPath('//input[@name="q"]')->attr('value'));
        self::assertSame((string) $match->getId(), $view->filterXPath('//figure[@data-media-id]')->attr('data-media-id'));
        self::assertCount(1, $view->filterXPath('//figure[@data-media-id]'));
        $view = $client->request('GET', '/media?q=NONEXISTENT&lang=de');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('keine Bilder gefunden', $view->filterXPath('//*[@role="status"]')->text());
        $view = $client->request('GET', '/media?page=1001&q=Summer');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('page=1000', $view->filterXPath('//a[@rel="prev"]')->attr('href'));
        self::assertStringContainsString('1001', $view->filterXPath('//*[@aria-current="page" and self::span]')->last()->text());
        $view = $client->request('GET', '/media?page=-4');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $view->filterXPath('//figure[@data-media-id]'));
    }

    public function testPublicThumbnailsAreBoundedAndGuardConditionalResponses(): void
    {
        $client = self::createClient();
        $asset = $this->asset('Public image');
        $id = $asset->getId();
        $url = '/media/'.$id.'/thumbnail';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $info = getimagesizefromstring((string) $client->getResponse()->getContent());
        self::assertIsArray($info);
        self::assertSame([400, 200], [$info[0], $info[1]]);
        $etag = $client->getResponse()->headers->get('ETag');
        self::assertStringContainsString('private', $client->getResponse()->headers->get('Cache-Control'));
        $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $foreign = new Site('Foreign', 'foreign-gallery.test');
        $em->persist($foreign);
        $em->flush();
        foreach (['http://foreign-gallery.test', 'http://unknown-gallery.test'] as $host) {
            $client->request('GET', $host.$url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(404);
        }
        $client->request('GET', 'http://localhost'.$url);
        self::assertResponseIsSuccessful();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $settings = $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $this->site()]);
        $settings->setModuleTogglesJson(['media' => false]);
        $em->flush();
        foreach (['/media', $url] as $path) {
            $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(404);
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $settings = $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $this->site()]);
        $settings->setModuleTogglesJson(['media' => true]);
        $this->site()->setMaintenanceEnabled(true);
        $em->flush();
        foreach (['/media', $url] as $path) {
            $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(503);
        }
    }

    public function testGlobalCmsSwitchAlsoDisablesTheNewImageRoute(): void
    {
        $client = self::createClient();
        $asset = $this->asset('Public image');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $setting = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => 'cms']);
        $previous = $setting?->isEnabled();
        if (null === $setting) {
            $setting = new ModuleSetting('cms', '1', true);
            $em->persist($setting);
        }
        $setting->setEnabled(false);
        $em->flush();
        try {
            foreach (['/media', '/media/'.$asset->getId().'/thumbnail'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(404);
            }
        } finally {
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $setting = $em->getRepository(ModuleSetting::class)->findOneBy(['moduleKey' => 'cms']);
            null === $previous ? $em->remove($setting) : $setting->setEnabled($previous);
            $em->flush();
        }
    }

    public function testInvalidMissingAndLegacyImagesRemainSafeAndManageable(): void
    {
        $client = self::createClient();
        $missing = $this->asset('Missing image', '/uploads/missing-gallery.png');
        $remote = $this->asset('Remote image', 'https://images.example.test/image.jpg');
        $this->asset('Unsafe action', '/logout');
        $this->asset('Unsafe script', 'javascript:alert(1)');
        $this->asset('Unsafe data', 'data:image/svg+xml,secret');
        $null = $this->asset('UNTYPED-SECRET', mime: null);
        $pdf = $this->asset('PDF-SECRET', mime: 'application/pdf');
        $svgFile = $this->directory.'/legacy.svg';
        file_put_contents($svgFile, '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"/>');
        $this->files[] = $svgFile;
        $svg = $this->asset('0', $this->relative.'/legacy.svg');
        $escape = $this->directory.'/escape.png';
        symlink('/etc/passwd', $escape);
        $this->files[] = $escape;
        $escaped = $this->asset('Escaping file', $this->relative.'/escape.png');
        $view = $client->request('GET', '/media?lang=en');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $view->filterXPath('//figure//img'));
        self::assertSame($svg->getPath(), $view->filterXPath('//figure[@data-media-id="'.$svg->getId().'"]//img')->attr('src'));
        self::assertSame('0', $view->filterXPath('//figure[@data-media-id="'.$svg->getId().'"]/figcaption')->text());
        self::assertSame($remote->getPath(), $view->filterXPath('//figure[@data-media-id="'.$remote->getId().'"]//img')->attr('src'));
        self::assertSame($missing->getPath(), $view->filterXPath('//figure[@data-media-id="'.$missing->getId().'"]//a')->attr('href'));
        self::assertCount(0, $view->filterXPath('//img[@src="/logout"]|//a[@href="/logout"]|//img[starts-with(@src,"javascript:")]|//img[starts-with(@src,"data:")]'));
        foreach ([$null, $pdf, $escaped, $missing, $remote] as $asset) {
            $client->request('GET', '/media/'.$asset->getId().'/thumbnail');
            self::assertResponseStatusCodeSame(404);
        }
    }

    public static function themes(): iterable
    {
        foreach (ThemeResolver::SUPPORTED_THEMES as $theme) {
            yield $theme => [$theme];
        }
    }

    #[DataProvider('themes')]
    public function testGalleryWorksWithEveryExistingTheme(string $theme): void
    {
        $client = self::createClient();
        $this->asset('Sample image');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $settings = $em->getRepository(CmsSiteSettings::class)->findOneBy(['site' => $this->site()]);
        $settings->setActiveTheme($theme);
        $em->flush();
        $view = $client->request('GET', '/media?lang=de');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//link[contains(@href,"cms-media-gallery.css")]'));
        self::assertCount(1, $view->filterXPath('//figure[@data-media-id]'));
        self::assertCount(1, $view->filterXPath('//form[@role="search"]'));
        self::assertSame('Medien', $view->filterXPath('//main//h1')->text());
        $view = $client->request('GET', '/media?lang=en');
        self::assertSame(['en', 'Search media', false], [
            $view->filterXPath('//html')->attr('lang'),
            $view->filterXPath('//label[@for="cms-media-search"]')->text(),
            str_contains((string) $client->getResponse()->getContent(), 'Clan-Alltag'),
        ]);
        foreach (['/blog', '/events', '/teams'] as $url) {
            $view = $client->request('GET', $url);
            self::assertResponseIsSuccessful($theme.' '.$url);
            self::assertCount(1, $view->filterXPath('//main//h1'), $theme.' '.$url);
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = $em->getRepository(\App\Module\Core\Domain\Entity\CmsPage::class)->findOneBy(['site' => $this->site(), 'slug' => 'startseite']);
        $block = new \App\Module\Core\Domain\Entity\CmsBlock($page, 'rich_text', '<p>KEPT-LEGACY-CMS-BODY</p>', 10);
        $em->persist($block);
        $em->flush();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('KEPT-LEGACY-CMS-BODY', (string) $client->getResponse()->getContent());
    }

    private function site(): Site
    {
        $site = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        self::assertInstanceOf(Site::class, $site);

        return $site;
    }

    private function asset(string $title, ?string $path = null, ?string $mime = 'image/png', ?Site $owner = null): MediaAsset
    {
        $asset = new MediaAsset($path ?? $this->relative.'/image.png');
        $asset->setTitle($title);
        $asset->setMime($mime);
        $asset->setSite($owner ?? $this->site());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($asset);
        $em->flush();

        return $asset;
    }
}
