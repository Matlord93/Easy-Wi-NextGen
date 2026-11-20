<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Cms\Application\CmsContentReferences;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\Site;
use PHPUnit\Framework\TestCase;

final class CmsContentReferencesTest extends TestCase
{
    public function testLegacyAndRichTextLinksAndMediaAreExtractedWithoutExecutingHtml(): void
    {
        $page = new CmsPage(new Site('Test', 'cms.test'), 'Test', 'test', false);
        foreach ([1, 2] as $version) {
            $html = '<a href="/größen?x=1&amp;y=2">Link</a><img src="/uploads/bild.png"><video src="/uploads/video.mp4" poster="/uploads/poster.png"></video><script>throw new Error("must not run")</script><iframe src="https://external.invalid/"></iframe>';
            $block = new CmsBlock($page, 'rich_text', $html, 0);
            $block->setVersion($version);
            if (2 === $version) {
                $block->setPayloadJson(['content' => $html]);
            }
            $result = (new CmsContentReferences())->extract($block);
            self::assertSame([
                ['kind' => 'link', 'url' => '/größen?x=1&y=2'],
                ['kind' => 'media', 'url' => '/uploads/bild.png'],
                ['kind' => 'media', 'url' => '/uploads/video.mp4'],
                ['kind' => 'media', 'url' => '/uploads/poster.png'],
            ], $result['references']);
            self::assertFalse($result['truncated']);
        }
    }

    public function testHeroImageAndCardsFieldsAreIncluded(): void
    {
        $page = new CmsPage(new Site('Test', 'cms.test'), 'Test', 'test', false);
        foreach ([
            ['hero', ['ctaUrl' => '/angebot', 'backgroundImagePath' => '/uploads/hero.png'], [['kind' => 'link', 'url' => '/angebot'], ['kind' => 'media', 'url' => '/uploads/hero.png']]],
            ['image', ['path' => '/uploads/photo.png'], [['kind' => 'media', 'url' => '/uploads/photo.png']]],
            ['cards', ['cards' => [['url' => '/project'], 'invalid-entry']], [['kind' => 'link', 'url' => '/project']]],
        ] as [$type, $payload, $expected]) {
            $block = new CmsBlock($page, $type, '', 0);
            $block->setVersion(2);
            $block->setPayloadJson($payload);
            self::assertSame($expected, (new CmsContentReferences())->extract($block)['references']);
        }
    }

    public function testLargeContentAndReferenceListsHaveVisibleLimits(): void
    {
        $page = new CmsPage(new Site('Test', 'cms.test'), 'Test', 'test', false);
        $block = new CmsBlock($page, 'text', str_repeat('<a href="/target">Link</a>', 600), 0);
        $result = (new CmsContentReferences())->extract($block);
        self::assertCount(500, $result['references']);
        self::assertTrue($result['truncated']);
        $block->setContent(str_repeat('a', 262145));
        self::assertTrue((new CmsContentReferences())->extract($block)['truncated']);
    }
}
