<?php

declare(strict_types=1);

namespace App\Tests\Controller;

final class PublicViewerNotFoundTest extends AbstractWebTestCase
{
    public function testUnknownTs6ViewerPageReturnsPlainNotFoundResponseWithoutThrowing(): void
    {
        $this->seedSite();

        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/viewer/ts6/missing-public-id');

        self::assertSame('ts6_viewer_page', $client->getRequest()->attributes->get('_route'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Viewer not found.', $client->getResponse()->getContent());
    }

    public function testUnknownTs6ViewerWidgetReturnsPlainNotFoundResponseWithoutThrowing(): void
    {
        $this->seedSite();

        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/viewer/ts6/missing-public-id.js');

        self::assertSame('ts6_viewer_widget', $client->getRequest()->attributes->get('_route'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Viewer not found.', $client->getResponse()->getContent());
    }

    public function testUnknownTs3ViewerPageReturnsPlainNotFoundResponseWithoutThrowing(): void
    {
        $this->seedSite();

        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/viewer/ts3/missing-public-id');

        self::assertSame('ts3_viewer_page', $client->getRequest()->attributes->get('_route'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Viewer not found.', $client->getResponse()->getContent());
    }

    public function testUnknownTs3ViewerWidgetReturnsPlainNotFoundResponseWithoutThrowing(): void
    {
        $this->seedSite();

        $client = static::createClient();
        $client->catchExceptions(false);

        $client->request('GET', '/viewer/ts3/missing-public-id.js');

        self::assertSame('ts3_viewer_widget', $client->getRequest()->attributes->get('_route'));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Viewer not found.', $client->getResponse()->getContent());
    }
}
