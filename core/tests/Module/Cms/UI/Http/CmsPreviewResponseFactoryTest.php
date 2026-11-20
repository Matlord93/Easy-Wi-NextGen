<?php

declare(strict_types=1);

namespace App\Tests\Module\Cms\UI\Http;

use App\Module\Cms\UI\Http\CmsPreviewResponseFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class CmsPreviewResponseFactoryTest extends TestCase
{
    public function testPreviewDisablesActionsAndOnlyAllowsStaticLocalResources(): void
    {
        $html = '<!doctype html><html lang="de"><head><link rel="stylesheet" href="/cms-themes.css"><meta http-equiv="refresh" content="0;url=/logout"><base href="https://external.test/"></head><body onload="alert(1)"><h1>Vorschau: Größe</h1><script>alert(1)</script><svg onload="alert(2)"></svg><iframe src="/logout"></iframe><object data="/logout"></object><a href="/logout" ping="/delete">Abmelden</a><form action="/delete"><button formaction="/delete" autofocus>Ausführen</button></form><img src="/logout"><img src="https://external.test/image.png"></body></html>';
        $response = (new CmsPreviewResponseFactory())->create($html, Request::create('https://cms.example.test/preview'), 'light');
        $view = new Crawler($response->getContent());
        self::assertSame('Vorschau: Größe', $view->filterXPath('//h1')->text());
        self::assertCount(0, $view->filterXPath('//script | //base | //iframe | //object | //*[@onload or @href and not(self::link) or @action or @formaction or @ping or @autofocus] | //meta[@http-equiv="refresh"]'));
        self::assertCount(1, $view->filterXPath('//link[@rel="stylesheet" and @href="/cms-themes.css"]'));
        self::assertCount(1, $view->filterXPath('//body[@inert]'));
        $csp = $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("sandbox; default-src 'none'; script-src 'none'", $csp);
        self::assertStringContainsString('img-src data: https://cms.example.test/uploads/', $csp);
        self::assertStringNotContainsString("img-src 'self'", $csp);
        self::assertStringContainsString("form-action 'none'", $csp);
        self::assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
