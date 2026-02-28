<?php

declare(strict_types=1);

namespace App\Tests\Extension;

use App\Extension\Twig\BbcodeTwigExtension;
use PHPUnit\Framework\TestCase;

final class BbcodeTwigExtensionTest extends TestCase
{
    public function testRejectsJavascriptScheme(): void
    {
        $extension = new BbcodeTwigExtension();

        $html = $extension->toHtml('[url=javascript:alert(1)]click[/url]');

        self::assertSame('click', $html);
    }

    public function testRejectsObfuscatedJavascriptSchemes(): void
    {
        $extension = new BbcodeTwigExtension();

        $html = $extension->toHtml('[url=java&#x73;cript:alert(1)]x[/url] [url=\n\r\tjavascript:alert(1)]y[/url] [url=//evil.test]z[/url]');

        self::assertSame('x y z', $html);
    }

    public function testEnforcesUrlHostAllowlistWhenConfigured(): void
    {
        $extension = new BbcodeTwigExtension(['example.test']);

        $html = $extension->toHtml('[url=https://evil.test/page]blocked[/url] [url=https://app.example.test/path]ok[/url]');

        self::assertStringNotContainsString('evil.test', $html);
        self::assertStringContainsString('https://app.example.test/path', $html);
    }

    public function testSupportsEntityDecodedLinks(): void
    {
        $extension = new BbcodeTwigExtension(['example.test']);

        $html = $extension->toHtml('[url]https://app.example.test/?a=1&amp;b=2[/url]');

        self::assertStringContainsString('href="https://app.example.test/?a=1&amp;amp;b=2" target="_blank" rel="noopener noreferrer"', $html);
    }

    public function testRendersCodeAsPreCodeWithoutLineBreakConversion(): void
    {
        $extension = new BbcodeTwigExtension();

        $html = $extension->toHtml("before\n[code]if (x < 1) {\n  alert('x');\n}[/code]\nafter");

        self::assertStringContainsString('before<br>', $html);
        self::assertStringContainsString('<pre><code>if (x &lt; 1) {' . "\n" . '  alert(&#039;x&#039;);' . "\n" . '}</code></pre>', $html);
        self::assertStringContainsString("<br>\nafter", $html);
    }

    public function testSupportsTelScheme(): void
    {
        $extension = new BbcodeTwigExtension();

        $html = $extension->toHtml('[url=tel:+49123456]Call[/url]');

        self::assertStringContainsString('href="tel:+49123456"', $html);
    }
}
