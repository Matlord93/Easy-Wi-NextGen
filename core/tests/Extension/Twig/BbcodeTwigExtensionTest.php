<?php

declare(strict_types=1);

namespace App\Tests\Extension\Twig;

use App\Extension\Twig\BbcodeTwigExtension;
use PHPUnit\Framework\TestCase;

final class BbcodeTwigExtensionTest extends TestCase
{
    public function testItEscapesHtmlAndKeepsLineBreaks(): void
    {
        $extension = new BbcodeTwigExtension();

        $html = $extension->toHtml("Hallo<script>alert(1)</script>\n[b]Welt[/b]");

        self::assertStringContainsString('Hallo&lt;script&gt;alert(1)&lt;/script&gt;<br>', $html);
        self::assertStringContainsString('<strong>Welt</strong>', $html);
    }

    public function testItRejectsUnsafeUrlSchemes(): void
    {
        $extension = new BbcodeTwigExtension();

        $html = $extension->toHtml('[url=javascript:alert(1)]Klick[/url]');

        self::assertSame('Klick', $html);
    }
}
