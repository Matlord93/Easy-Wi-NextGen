<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CmsMediaLibraryHttpTest extends AbstractWebTestCase
{
    private string $directory;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->directory = dirname(__DIR__, 2).'/public/uploads/cms-library-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testMetadataEditingPreservesFilesOwnershipAndExistingBlockText(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $asset = $this->asset($site, 'Original title');
        $page = new CmsPage($site, 'Image page', 'media-test-page', false);
        $block = new CmsBlock($page, 'image', '', 0);
        $block->setVersion(2);
        $block->setPayloadJson(['path' => $asset->getPath(), 'alt' => 'Block-specific description', 'caption' => 'Kept caption']);
        $em->persist($page);
        $em->persist($block);
        $em->flush();
        $id = $asset->getId();
        $blockId = $block->getId();
        $before = $this->stored($id);
        $blockBefore = $block->getPayloadJson();
        $file = dirname(__DIR__, 2).'/public'.$asset->getPath();
        $hash = hash_file('sha256', $file);
        $view = $client->request('GET', '/admin/cms/media/'.$id.'/edit?q=Original&page=2');
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->stored($id));
        $token = $view->filterXPath('//form[@data-media-edit]//input[@name="_token"]')->attr('value');
        $client->request('POST', '/admin/cms/media/'.$id.'?q=Original&page=2', ['_token' => 'wrong', 'title' => 'Rejected']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->stored($id));
        $client->request('POST', '/admin/cms/media/'.$id.'?q=Original&page=2', ['_token' => $token, 'title' => 'New title', 'alt' => 'New media description', 'path' => '/replaced.png', 'site_id' => '9999', 'mime' => 'text/plain', 'size' => '1']);
        self::assertResponseRedirects('/admin/cms/media?q=Original&page=2&saved=1', 303);
        $after = $this->stored($id);
        self::assertSame('New title', $after['title']);
        self::assertSame('New media description', $after['alt']);
        unset($before['title'], $before['alt'], $after['title'], $after['alt']);
        self::assertSame($before, $after);
        self::assertSame($hash, hash_file('sha256', $file));
        self::assertSame($blockBefore, self::getContainer()->get(EntityManagerInterface::class)->find(CmsBlock::class, $blockId)->getPayloadJson());
        $view = $client->request('GET', '/admin/cms/media/picker?field=image_path&q=New%20title');
        self::assertResponseIsSuccessful();
        self::assertSame('New media description', $view->filterXPath('//button[@data-cms-media-id="'.$id.'"]')->attr('data-cms-media-alt'));
    }

    public function testValidationPreservesInputAndEmptyValuesAndZeroAreHandledDeliberately(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $asset = $this->asset($site, 'Original');
        $em->flush();
        $id = $asset->getId();
        $before = $this->stored($id);
        $view = $client->request('GET', '/admin/cms/media/'.$id.'/edit');
        $token = $view->filterXPath('//input[@name="_token"]')->attr('value');
        $title = str_repeat('ä', 181);
        $alt = str_repeat('b', 181);
        $view = $client->request('POST', '/admin/cms/media/'.$id, ['_token' => $token, 'title' => $title, 'alt' => $alt]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($title, $view->filterXPath('//input[@name="title"]')->attr('value'));
        self::assertSame($alt, $view->filterXPath('//input[@name="alt"]')->attr('value'));
        self::assertSame($before, $this->stored($id));
        $client->request('POST', '/admin/cms/media/'.$id, ['_token' => $token, 'title' => '0', 'alt' => '0']);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('0', $this->stored($id)['title']);
        self::assertSame('0', $this->stored($id)['alt']);
        $client->request('POST', '/admin/cms/media/'.$id, ['_token' => $token, 'title' => ' ', 'alt' => '']);
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->stored($id)['title']);
        self::assertNull($this->stored($id)['alt']);
    }

    public function testForeignAndUnassignedMediaCannotBeEditedOrFoundThroughSearch(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = new Site('Foreign', 'foreign-library.test');
        $em->persist($site);
        $foreign = $this->asset($site, 'FOREIGN-SECRET');
        $orphan = $this->asset(null, 'ORPHAN-SECRET');
        $em->flush();
        foreach ([$foreign->getId(), $orphan->getId()] as $id) {
            $token = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('cms_media_update_'.$id)->getValue();
            $client->request('GET', '/admin/cms/media/'.$id.'/edit');
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/admin/cms/media/'.$id, ['_token' => $token, 'title' => 'Changed']);
            self::assertResponseStatusCodeSame(404);
        }
        $view = $client->request('GET', '/admin/cms/media?q=SECRET');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $view->filterXPath('//tr[@data-media-id]'));
        self::assertStringNotContainsString('FOREIGN-SECRET', (string) $client->getResponse()->getContent());
        $client->getCookieJar()->clear();
        $client->request('GET', '/admin/cms/media/'.$foreign->getId().'/edit');
        self::assertResponseStatusCodeSame(401);
        $this->loginAsRole($client, 'customer');
        $client->request('POST', '/admin/cms/media/'.$foreign->getId(), ['title' => 'Changed']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('FOREIGN-SECRET', $this->stored($foreign->getId())['title']);
    }

    public function testUploadValidatesTokenAndLengthsBeforeMovingTheFile(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $temporary = tempnam(sys_get_temp_dir(), 'cms-upload-');
        $this->files[] = $temporary;
        file_put_contents($temporary, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGQAAAABJRU5ErkJggg==', true));
        $file = new UploadedFile($temporary, 'test.png', 'image/png', test: true);
        $client->request('POST', '/admin/cms/media', ['title' => 'Blocked'], ['file' => $file]);
        self::assertResponseStatusCodeSame(403);
        self::assertFileExists($temporary);
        $view = $client->request('GET', '/admin/cms/media');
        $token = $view->filterXPath('//form[@data-media-upload]//input[@name="_token"]')->attr('value');
        $partial = new UploadedFile($temporary, 'partial.png', 'image/png', error: UPLOAD_ERR_PARTIAL, test: true);
        $view = $client->request('POST', '/admin/cms/media', ['_token' => $token, 'title' => 'Retained after interrupted upload'], ['file' => $partial]);
        self::assertResponseStatusCodeSame(422);
        self::assertFileExists($temporary);
        self::assertSame('Retained after interrupted upload', $view->filterXPath('//form[@data-media-upload]//input[@name="title"]')->attr('value'));
        $view = $client->request('POST', '/admin/cms/media', ['_token' => $token, 'title' => str_repeat('x', 181), 'alt' => 'Kept input'], ['file' => $file]);
        self::assertResponseStatusCodeSame(422);
        self::assertFileExists($temporary);
        self::assertSame('Kept input', $view->filterXPath('//form[@data-media-upload]//input[@name="alt"]')->attr('value'));
        self::assertSame('create', $view->filterXPath('//*[@data-panel-tabs="cms-media"]')->attr('data-panel-active'));
        $client->request('POST', '/admin/cms/media', ['_token' => $token, 'title' => '0', 'alt' => '0'], ['file' => $file]);
        self::assertResponseRedirects('/admin/cms/media');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $asset = $em->getRepository(MediaAsset::class)->findOneBy(['site' => $site, 'title' => '0']);
        self::assertNotNull($asset);
        self::assertSame('0', $asset->getAlt());
        $this->files[] = dirname(__DIR__, 2).'/public'.$asset->getPath();
        self::assertFileExists($this->files[array_key_last($this->files)]);
    }

    public function testDeleteRequiresTheRecordTokenAndPreservesForeignMedia(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        $asset = $this->asset($site, 'Own');
        $other = new Site('Foreign', 'delete-foreign.test');
        $em->persist($other);
        $foreign = $this->asset($other, 'Foreign');
        $em->flush();
        $file = dirname(__DIR__, 2).'/public'.$asset->getPath();
        $client->request('POST', '/admin/cms/media/'.$asset->getId().'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertFileExists($file);
        $view = $client->request('GET', '/admin/cms/media');
        $token = $view->filterXPath('//tr[@data-media-id="'.$asset->getId().'"]//input[@name="_token"]')->attr('value');
        $client->request('POST', '/admin/cms/media/'.$foreign->getId().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/admin/cms/media/'.$asset->getId().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/admin/cms/media');
        self::assertFileDoesNotExist($file);
        self::assertSame('Foreign', $this->stored($foreign->getId())['title']);
    }

    public function testListPaginationSearchAndPreviewDoNotLoadUnrelatedOrActionUrls(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $site = $em->getRepository(Site::class)->findOneBy(['host' => 'localhost']);
        for ($index = 0; $index < 30; ++$index) {
            $asset = new MediaAsset(29 === $index ? '/logout' : '/uploads/file-'.$index.'.pdf');
            $asset->setSite($site);
            $asset->setTitle('File '.$index);
            $asset->setMime('application/pdf');
            $em->persist($asset);
        }
        $em->flush();
        $view = $client->request('GET', '/admin/cms/media');
        self::assertResponseIsSuccessful();
        self::assertCount(24, $view->filterXPath('//tr[@data-media-id]'));
        self::assertCount(0, $view->filterXPath('//section[@data-panel-section="overview"]//img'));
        $first = $view->filterXPath('//tr[@data-media-id]')->extract(['data-media-id']);
        $view = $client->request('GET', '/admin/cms/media?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(6, $view->filterXPath('//tr[@data-media-id]'));
        $second = $view->filterXPath('//tr[@data-media-id]')->extract(['data-media-id']);
        self::assertSame([], array_intersect(array_column($first, 0), array_column($second, 0)));
        $view = $client->request('GET', '/admin/cms/media?q=File%2029');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $view->filterXPath('//tr[@data-media-id]'));
        self::assertCount(0, $view->filterXPath('//img[@src="/logout"]'));
    }

    /** @return array<array-key,mixed> */
    private function stored(int $id): array
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative('SELECT * FROM media_assets WHERE id = ?', [$id]);
    }

    private function asset(?Site $site, string $title): MediaAsset
    {
        $file = $this->directory.'/'.bin2hex(random_bytes(5)).'.png';
        $this->files[] = $file;
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGQAAAABJRU5ErkJggg==', true));
        $asset = new MediaAsset('/uploads/'.basename($this->directory).'/'.basename($file));
        $asset->setSite($site);
        $asset->setTitle($title);
        $asset->setAlt('Original alternative text');
        $asset->setMime('image/png');
        $asset->setSize(filesize($file));
        self::getContainer()->get(EntityManagerInterface::class)->persist($asset);

        return $asset;
    }
}
