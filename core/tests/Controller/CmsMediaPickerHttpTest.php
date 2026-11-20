<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

final class CmsMediaPickerHttpTest extends AbstractWebTestCase
{
    /** @var list<string> */
    private array $files = [];
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->directory = dirname(__DIR__, 2).'/public/uploads/cms-picker-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
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

    public function testOnlyAvailableImagesOfTheCurrentWebsiteAreOffered(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $own = $this->image($site, 'Own image');
        $legacy = $this->image($site, 'Legacy image');
        $legacy->setMime(null);
        $foreignSite = new Site('Foreign media', 'foreign-media.test');
        $em->persist($foreignSite);
        $this->image($foreignSite, 'FOREIGN-MEDIA-SECRET');
        $this->image(null, 'UNASSIGNED-MEDIA-SECRET');
        $nonImage = $this->image($site, 'NOT-AN-IMAGE');
        $nonImage->setMime('text/plain');
        foreach (['/uploads/missing-picker-image.png', '/logout', '/uploads/../private.png', 'https://external.invalid/remote.png'] as $path) {
            $asset = new MediaAsset($path);
            $asset->setSite($site);
            $asset->setMime('image/png');
            $asset->setTitle('UNAVAILABLE-MEDIA');
            $em->persist($asset);
        }
        $em->flush();
        $before = $em->getConnection()->fetchAllAssociative('SELECT * FROM media_assets ORDER BY id');
        $view = $client->request('GET', '/admin/cms/media/picker?field=image_path');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $view->filterXPath('//button[@data-cms-media-select]'));
        self::assertSame($own->getPath(), $view->filterXPath('//button[@data-cms-media-id="'.$own->getId().'"]')->attr('data-cms-media-path'));
        self::assertSame('Own image', $view->filterXPath('//button[@data-cms-media-id="'.$own->getId().'"]')->attr('data-cms-media-alt'));
        foreach (['FOREIGN-MEDIA-SECRET', 'UNASSIGNED-MEDIA-SECRET', 'UNAVAILABLE-MEDIA', 'NOT-AN-IMAGE', 'external.invalid'] as $hidden) {
            self::assertStringNotContainsString($hidden, (string) $client->getResponse()->getContent());
        }
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame($before, $em->getConnection()->fetchAllAssociative('SELECT * FROM media_assets ORDER BY id'));
        $view = $client->request('GET', '/admin/cms/media/picker?field=image_path&q=FOREIGN-MEDIA');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $view->filterXPath('//button[@data-cms-media-select]'));
    }

    public function testPaginationIsBoundedStableAndSearchTreatsWildcardsLiterally(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        for ($index = 0; $index < 30; ++$index) {
            $this->image($site, 'Bild '.$index);
        }
        $em->flush();
        $view = $client->request('GET', '/admin/cms/media/picker?field=content');
        self::assertResponseIsSuccessful();
        $first = $view->filterXPath('//button[@data-cms-media-select]')->extract(['data-cms-media-id']);
        self::assertCount(24, $first);
        self::assertCount(1, $view->filterXPath('//button[contains(@hx-get,"page=2") and contains(@hx-get,"field=content")]'));
        $view = $client->request('GET', '/admin/cms/media/picker?field=content&page=2');
        self::assertResponseIsSuccessful();
        $second = $view->filterXPath('//button[@data-cms-media-select]')->extract(['data-cms-media-id']);
        self::assertCount(6, $second);
        self::assertSame([], array_intersect(array_column($first, 0), array_column($second, 0)));
        self::assertCount(0, $view->filterXPath('//button[contains(@hx-get,"page=3")]'));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $this->image($site, '100% Bild');
        $this->image($site, '1000 Bild');
        $em->flush();
        $view = $client->request('GET', '/admin/cms/media/picker?field=hero_background_image_path&q=100%25');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//button[@data-cms-media-select]'));
        self::assertStringContainsString('100% Bild', $view->text());
    }

    public function testPickerRequiresAnAdminAndAnAllowedFieldAndDoesNotAcceptPosts(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/cms/media/picker?field=image_path');
        self::assertResponseStatusCodeSame(401);
        $this->loginAsRole($client, 'customer');
        $client->request('GET', '/admin/cms/media/picker?field=image_path');
        self::assertResponseStatusCodeSame(401);
        $client->getCookieJar()->clear();
        $this->loginAsRole($client, 'admin');
        foreach (['', 'password', 'image_path%22%5D'] as $field) {
            $client->request('GET', '/admin/cms/media/picker?field='.$field);
            self::assertResponseStatusCodeSame(400);
        }
        $client->request('POST', '/admin/cms/media/picker', ['field' => 'image_path']);
        self::assertResponseStatusCodeSame(405);
    }

    public function testTheEditorLoadsMediaOnDemandAndPreservesSavedAndRejectedInputs(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $page = new CmsPage($site, 'Image editor', 'image-editor', false);
        $image = $this->image($site, 'PICKER-IMAGE-TITLE');
        $em->persist($page);
        $em->flush();
        $id = $page->getId();
        $view = $client->request('GET', '/admin/cms/pages/'.$id);
        self::assertResponseIsSuccessful();
        self::assertCount(4, $view->filterXPath('//button[@data-cms-media-open]'));
        self::assertCount(1, $view->filterXPath('//dialog[@id="cms-media-picker" and not(ancestor::form)]'));
        self::assertCount(0, $view->filterXPath('//*[@data-cms-media-select]'));
        self::assertStringNotContainsString('PICKER-IMAGE-TITLE', (string) $client->getResponse()->getContent());
        $client->request('POST', '/admin/cms/pages/'.$id.'/blocks', ['version' => '2', 'type' => 'image', 'image_path' => $image->getPath(), 'image_alt' => 'Existing meaningful alt', 'image_caption' => 'Kept caption']);
        self::assertResponseIsSuccessful();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $block = $em->getRepository(CmsBlock::class)->findOneBy(['page' => $id]);
        self::assertSame(['path' => $image->getPath(), 'alt' => 'Existing meaningful alt', 'caption' => 'Kept caption'], $block->getPayloadJson());
        $view = $client->request('GET', '/admin/cms/pages/'.$id.'/blocks/'.$block->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSame($image->getPath(), $view->filterXPath('//input[@name="image_path"]')->attr('value'));
        self::assertSame('Existing meaningful alt', $view->filterXPath('//input[@name="image_alt"]')->attr('value'));
        $view = $client->request('POST', '/admin/cms/pages/'.$id.'/blocks', ['version' => '2', 'type' => 'image', 'image_path' => '', 'image_alt' => 'Unsaved alternative text', 'image_caption' => 'Unsaved caption']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('Unsaved alternative text', $view->filterXPath('//input[@name="image_alt"]')->attr('value'));
        self::assertSame('Unsaved caption', $view->filterXPath('//input[@name="image_caption"]')->attr('value'));
        self::assertCount(4, $view->filterXPath('//button[@data-cms-media-open]'));
    }

    public function testFileSymlinksCannotEscapeTheUploadsDirectory(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $outside = tempnam(sys_get_temp_dir(), 'cms-private-');
        $this->files[] = $outside;
        file_put_contents($outside, 'Private content');
        $file = $this->directory.'/escape.png';
        symlink($outside, $file);
        $this->files[] = $file;
        $asset = new MediaAsset('/uploads/'.basename($this->directory).'/escape.png');
        $asset->setSite($site);
        $asset->setMime('image/png');
        $em->persist($asset);
        $em->flush();
        $view = $client->request('GET', '/admin/cms/media/picker?field=image_path');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $view->filterXPath('//button[@data-cms-media-select]'));
        self::assertStringNotContainsString('Private content', (string) $client->getResponse()->getContent());
    }

    private function image(?Site $site, string $title): MediaAsset
    {
        $file = $this->directory.'/'.bin2hex(random_bytes(5)).'.png';
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGQAAAABJRU5ErkJggg==', true));
        $this->files[] = $file;
        $asset = new MediaAsset('/uploads/'.basename($this->directory).'/'.basename($file));
        $asset->setSite($site);
        $asset->setMime('image/png');
        $asset->setTitle($title);
        self::getContainer()->get(EntityManagerInterface::class)->persist($asset);

        return $asset;
    }
}
