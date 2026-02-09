<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Module\Core\EventSubscriber\ApiDeprecationSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiDeprecationSubscriberTest extends TestCase
{
    #[Test]
    public function itAddsDeprecationHeadersToLegacyApiRoutes(): void
    {
        $request = Request::create('/api/instances');
        $response = new Response();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber = new ApiDeprecationSubscriber(new NullLogger());
        $subscriber->onKernelResponse($event);

        self::assertSame('true', $response->headers->get('Deprecation'));
        self::assertSame('Wed, 31 Dec 2025 23:59:59 GMT', $response->headers->get('Sunset'));
    }

    #[Test]
    public function itSkipsDeprecationHeadersForVersionedApiRoutes(): void
    {
        $request = Request::create('/api/v1/auth/login');
        $response = new Response();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber = new ApiDeprecationSubscriber(new NullLogger());
        $subscriber->onKernelResponse($event);

        self::assertFalse($response->headers->has('Deprecation'));
        self::assertFalse($response->headers->has('Sunset'));
    }
}
