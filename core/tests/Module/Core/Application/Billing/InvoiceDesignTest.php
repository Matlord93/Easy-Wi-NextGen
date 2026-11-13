<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\InvoiceDesign;
use PHPUnit\Framework\TestCase;

final class InvoiceDesignTest extends TestCase
{
    public function testMissingGdIsReportedBeforeRenderingPngLogos(): void
    {
        if (!extension_loaded('gd')) {
            $this->expectExceptionMessage('billing.gd_required');
        }
        \App\Module\Core\Application\Billing\InvoicePdfRenderer::assertCapabilities('<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGPgmLiJJMQwqmFUw/DVAABOtksQes1kPQAAAABJRU5ErkJggg==">');
        self::assertTrue(extension_loaded('gd'));
    }

    public function testTextCannotInjectTwigAndStylesComeFromValidatedOptions(): void
    {
        $layout = InvoiceDesign::layout(array_replace(InvoiceDesign::defaults(), ['heading' => '{{ source("/etc/passwd") }}<script>', 'accent' => '#Aa00Ff', 'font_size' => '12', 'margin_mm' => '20', 'font' => 'serif']));
        self::assertStringContainsString('&#123;&#123; source(', $layout);
        self::assertStringNotContainsString('<script>', $layout);
        self::assertStringContainsString('#aa00ff', $layout);
        self::assertStringContainsString('DejaVu Serif', $layout);
        self::assertStringContainsString('margin:20mm', $layout);
        self::assertStringContainsString('for item in invoice.items', $layout);
    }

    public function testInvalidSettingsAndRemoteLogosAreRejected(): void
    {
        foreach ([['accent' => 'red;display:none'], ['font_size' => 100], ['font' => 'remote'], ['margin_mm' => 0], ['logo_data_uri' => 'https://example.test/logo.png'], ['logo_data_uri' => 'data:image/svg+xml;base64,PHN2Zz4=']] as $change) {
            try {
                InvoiceDesign::normalize(array_replace(InvoiceDesign::defaults(), $change));
                self::fail('Unsafe design accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringStartsWith('billing.invalid_', $error->getMessage());
            }
        }
    }

    public function testOnlyValidBoundedRasterImagesAreEmbedded(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGPgmLiJJMQwqmFUw/DVAABOtksQes1kPQAAAABJRU5ErkJggg==';
        self::assertSame($png, InvoiceDesign::logo($png));
        $this->expectException(\InvalidArgumentException::class);
        InvoiceDesign::logo(str_replace('image/png', 'image/jpeg', $png));
    }
}
