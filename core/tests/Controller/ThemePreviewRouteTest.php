<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThemePreviewRouteTest extends WebTestCase
{
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
