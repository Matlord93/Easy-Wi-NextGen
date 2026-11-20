<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;

final class ThemePreviewRouteTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
    }

    public static function newThemes(): iterable
    {
        yield ['hosting'];
        yield ['clan'];
        yield ['private'];
    }

    #[DataProvider('newThemes')]
    public function testNewPreviewDoesNotSwitchTheLiveTheme(string $theme): void
    {
        $client = static::createClient();
        $view = $client->request('GET', '/preview/'.$theme);
        self::assertResponseIsSuccessful();
        self::assertSame($theme, $view->filterXPath('//body')->attr('data-cms-theme'));
        self::assertStringContainsString('Preview Hero', (string) $client->getResponse()->getContent());
        self::assertCount(2, $view->filterXPath('//*[@data-cms-block]'));
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('theme-minimal', (string) $client->getResponse()->getContent());
    }

    public function testPreviewEsportsReturns200(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/preview/esports');
        self::assertResponseIsSuccessful();
    }

    public function testPreviewMinimalReturns200(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/preview/minimal');
        self::assertResponseIsSuccessful();
    }

    public function testPreviewFantasyReturns200(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/preview/fantasy');
        self::assertResponseIsSuccessful();
    }
}
