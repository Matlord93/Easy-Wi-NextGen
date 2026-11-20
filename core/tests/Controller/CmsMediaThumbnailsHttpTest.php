<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Cms\Application\CmsMediaPicker;
use App\Module\Cms\Application\CmsMediaThumbnails;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

final class CmsMediaThumbnailsHttpTest extends AbstractWebTestCase
{
    private string $directory;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->directory = dirname(__DIR__, 2).'/public/uploads/cms-thumbnails-'.bin2hex(random_bytes(6));
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

    public function testBoundedRenditionsKeepOriginalsAndReuseCache(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $asset = $this->asset($this->png(1200, 600));
        $path = dirname(__DIR__, 2).'/public'.$asset->getPath();
        $hash = hash_file('sha256', $path);
        $mtime = filemtime($path);
        $id = $asset->getId();
        $url = '/admin/cms/media/'.$id.'/thumbnail/picker';
        foreach (['list' => [144, 72], 'picker' => [400, 200], 'editor' => [800, 400]] as $variant => $expected) {
            $client->request('GET', '/admin/cms/media/'.$id.'/thumbnail/'.$variant);
            self::assertResponseIsSuccessful();
            $info = getimagesizefromstring((string) $client->getResponse()->getContent());
            self::assertIsArray($info);
            self::assertSame($expected, [$info[0], $info[1]]);
        }
        $client->request('GET', $url);
        self::assertContains($client->getResponse()->headers->get('Content-Type'), ['image/webp', 'image/png']);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('private', $client->getResponse()->headers->get('Cache-Control'));
        $etag = $client->getResponse()->headers->get('ETag');
        $cache = self::getContainer()->getParameter('kernel.cache_dir').'/cms-media-thumbnails/'.$id.'/picker.bin';
        self::assertFileExists($cache);
        touch($cache, 1234567890);
        $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
        clearstatcache(true, $cache);
        self::assertSame(1234567890, filemtime($cache));
        $view = $client->request('GET', '/admin/cms/media/picker?field=image_path');
        self::assertSame($asset->getPath(), $view->filterXPath('//button[@data-cms-media-id="'.$id.'"]')->attr('data-cms-media-path'));
        self::assertSame($url, $view->filterXPath('//button[@data-cms-media-id="'.$id.'"]/img')->attr('src'));
        self::assertSame($hash, hash_file('sha256', $path));
        self::assertSame($mtime, filemtime($path));
    }

    public function testCacheChangesWithContentEvenWhenTimestampAndSizeMatch(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $a = $this->png(50, 20, 30);
        $b = $this->png(50, 20, 220);
        $length = max(strlen($a), strlen($b));
        $asset = $this->asset(str_pad($a, $length, "\0"));
        $path = dirname(__DIR__, 2).'/public'.$asset->getPath();
        touch($path, 1234567890);
        $url = '/admin/cms/media/'.$asset->getId().'/thumbnail/list';
        $client->request('GET', $url);
        $etag = $client->getResponse()->headers->get('ETag');
        file_put_contents($path, str_pad($b, $length, "\0"));
        touch($path, 1234567890);
        $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseIsSuccessful();
        self::assertNotSame($etag, $client->getResponse()->headers->get('ETag'));
        $info = getimagesizefromstring((string) $client->getResponse()->getContent());
        self::assertIsArray($info);
        self::assertSame([50, 20], [$info[0], $info[1]]);
        unlink($path);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCorruptOversizedAndEscapingFilesAreHandled(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        foreach (['corrupt', str_repeat('x', 16 * 1024 * 1024 + 1)] as $bytes) {
            $asset = $this->asset($bytes);
            $client->request('GET', '/admin/cms/media/'.$asset->getId().'/thumbnail/list');
            self::assertResponseIsSuccessful();
            self::assertSame('image/svg+xml', $client->getResponse()->headers->get('Content-Type'));
        }
        $asset = $this->asset($this->png(5, 5));
        $path = dirname(__DIR__, 2).'/public'.$asset->getPath();
        unlink($path);
        symlink('/etc/passwd', $path);
        $client->request('GET', '/admin/cms/media/'.$asset->getId().'/thumbnail/list');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/admin/cms/media/'.$asset->getId().'/thumbnail/arbitrary');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOwnershipPrecedesConditionalRequests(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $asset = $this->asset($this->png(10, 10));
        $url = '/admin/cms/media/'.$asset->getId().'/thumbnail/list';
        $client->request('GET', $url);
        $etag = $client->getResponse()->headers->get('ETag');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $foreign = new Site('Other site', 'foreign-thumbnail.test');
        $em->persist($foreign);
        $stored = $em->find(MediaAsset::class, $asset->getId());
        self::assertInstanceOf(MediaAsset::class, $stored);
        $stored->setSite($foreign);
        $em->flush();
        $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $url, server: ['HTTP_HOST' => 'unknown-thumbnail.test', 'HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(401);
        $client->request('GET', '/login', server: ['HTTP_HOST' => 'localhost']);
        $client->getCookieJar()->clear();
        $this->loginAsRole($client, 'customer');
        $client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testTransparencyUnavailableCacheAndInvalidation(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $asset = $this->asset($this->png(20, 10));
        $service = self::getContainer()->get(CmsMediaThumbnails::class);
        $image = $service->image($asset, 'list');
        self::assertIsArray($image);
        $decoded = imagecreatefromstring($image['data']);
        self::assertInstanceOf(\GdImage::class, $decoded);
        self::assertSame(127, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
        $blocked = $this->directory.'/blocked-cache';
        file_put_contents($blocked, 'not a directory');
        $this->files[] = $blocked;
        $uncached = new CmsMediaThumbnails(self::getContainer()->get(CmsMediaPicker::class), $blocked);
        self::assertSame($image, $uncached->image($asset, 'list'));
        $service->invalidate($asset);
        self::assertFileDoesNotExist(self::getContainer()->getParameter('kernel.cache_dir').'/cms-media-thumbnails/'.$asset->getId().'/list.bin');
    }

    public function testSupportedRasterFormatsAndExcessiveDimensions(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $input = imagecreatefromstring($this->png(40, 20));
        self::assertInstanceOf(\GdImage::class, $input);
        foreach (['imagepng' => IMG_PNG, 'imagejpeg' => IMG_JPG, 'imagegif' => IMG_GIF, 'imagebmp' => IMG_BMP, 'imagewebp' => IMG_WEBP, 'imageavif' => IMG_AVIF] as $encode => $type) {
            if (0 === (imagetypes() & $type)) {
                continue;
            }
            ob_start();
            $encode($input);
            $asset = $this->asset((string) ob_get_clean());
            $client->request('GET', '/admin/cms/media/'.$asset->getId().'/thumbnail/list');
            self::assertResponseIsSuccessful();
            $info = getimagesizefromstring((string) $client->getResponse()->getContent());
            self::assertIsArray($info);
            self::assertSame([40, 20], [$info[0], $info[1]]);
        }
        $oversized = substr_replace($this->png(2, 2), pack('NN', 16000, 16000), 16, 8);
        $asset = $this->asset($oversized);
        $client->request('GET', '/admin/cms/media/'.$asset->getId().'/thumbnail/list');
        self::assertResponseIsSuccessful();
        self::assertSame('image/svg+xml', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testJpegOrientationIsPreservedWithoutAnExifExtension(): void
    {
        $client = self::createClient();
        $this->loginAsRole($client, 'admin');
        $input = imagecreatetruecolor(60, 30);
        $colors = [[220, 20, 20], [20, 220, 20], [20, 20, 220], [220, 220, 20]];
        foreach ($colors as $index => [$r, $g, $b]) {
            $x = ($index % 2) * 30;
            $y = intdiv($index, 2) * 15;
            imagefilledrectangle($input, $x, $y, $x + 29, $y + 14, imagecolorallocate($input, $r, $g, $b));
        }
        ob_start();
        imagejpeg($input, null, 95);
        $jpeg = (string) ob_get_clean();
        foreach ([1 => 0, 2 => 1, 3 => 3, 4 => 2, 5 => 0, 6 => 2, 7 => 3, 8 => 1] as $orientation => $corner) {
            foreach ([true, false] as $little) {
                $tiff = $little ? "II\x2a\0".pack('Vv', 8, 1).pack('vvVv', 274, 3, 1, $orientation)."\0\0" : "MM\0\x2a".pack('Nn', 8, 1).pack('nnNn', 274, 3, 1, $orientation)."\0\0";
                $exif = "Exif\0\0".$tiff.pack('N', 0);
                $asset = $this->asset(substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
                $client->request('GET', '/admin/cms/media/'.$asset->getId().'/thumbnail/list');
                self::assertResponseIsSuccessful();
                $decoded = imagecreatefromstring((string) $client->getResponse()->getContent());
                self::assertInstanceOf(\GdImage::class, $decoded);
                self::assertSame($orientation >= 5 ? [30, 60] : [60, 30], [imagesx($decoded), imagesy($decoded)]);
                $pixel = imagecolorsforindex($decoded, imagecolorat($decoded, 5, 5));
                foreach (['red', 'green', 'blue'] as $index => $channel) {
                    self::assertEqualsWithDelta($colors[$corner][$index], $pixel[$channel], 35, 'Orientation '.$orientation.' '.$channel);
                }
            }
        }
    }

    private function asset(string $bytes): MediaAsset
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $path = $this->directory.'/'.bin2hex(random_bytes(6)).'.png';
        file_put_contents($path, $bytes);
        $this->files[] = $path;
        $asset = new MediaAsset(substr($path, strlen(dirname(__DIR__, 2).'/public')));
        $asset->setSite($em->getRepository(Site::class)->findOneBy(['host' => 'localhost']));
        $asset->setMime('image/png');
        $asset->setTitle('Thumbnail fixture');
        $em->persist($asset);
        $em->flush();

        return $asset;
    }

    private function png(int $width, int $height, int $red = 80): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, $red, 100, 180, 127));
        imagefilledrectangle($image, 1, 1, $width - 1, $height - 1, imagecolorallocatealpha($image, $red, 100, 180, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
