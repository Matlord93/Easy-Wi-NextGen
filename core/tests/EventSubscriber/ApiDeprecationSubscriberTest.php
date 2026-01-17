<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Module\Core\EventSubscriber\ApiDeprecationSubscriber;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiDeprecationSubscriberTest extends KernelTestCase
{
    #[Test]
    public function itAddsDeprecationHeadersToLegacyApiRoutes(): void
    {
        self::bootKernel();

        $request = Request::create('/api/instances');
        $response = new Response();
        $event = new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber = new ApiDeprecationSubscriber(new NullLogger());
        $subscriber->onKernelResponse($event);

        self::assertSame('true', $response->headers->get('Deprecation'));
        self::assertSame('Wed, 31 Dec 2025 23:59:59 GMT', $response->headers->get('Sunset'));
    }

    #[Test]
    public function itSkipsDeprecationHeadersForVersionedApiRoutes(): void
    {
        self::bootKernel();

        $request = Request::create('/api/v1/auth/login');
        $response = new Response();
        $event = new ResponseEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber = new ApiDeprecationSubscriber(new NullLogger());
        $subscriber->onKernelResponse($event);

        self::assertFalse($response->headers->has('Deprecation'));
        self::assertFalse($response->headers->has('Sunset'));
    }
}
