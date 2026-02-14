<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceFileApiController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CustomerInstanceFileApiControllerTest extends TestCase
{
    public function testListUnauthorizedReturnsErrorEnvelope(): void
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('isCustomerDataManagerEnabled')->willReturn(true);

        $reflection = new \ReflectionClass(CustomerInstanceFileApiController::class);
        /** @var CustomerInstanceFileApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        $appSettingsProperty = $reflection->getProperty('appSettingsService');
        $appSettingsProperty->setAccessible(true);
        $appSettingsProperty->setValue($controller, $settings);

        $envelopeProperty = $reflection->getProperty('responseEnvelopeFactory');
        $envelopeProperty->setAccessible(true);
        $envelopeProperty->setValue($controller, new ResponseEnvelopeFactory());

        $request = Request::create('/api/instances/77/files');
        $request->headers->set('X-Request-ID', 'req-list-schema');

        $response = $controller->list($request, 77);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('failed', $payload['status']);
        self::assertSame('files_unauthorized', $payload['error_code']);
        self::assertSame('req-list-schema', $payload['request_id']);
    }

    public function testContentUnauthorizedReturnsErrorEnvelope(): void
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('isCustomerDataManagerEnabled')->willReturn(true);

        $reflection = new \ReflectionClass(CustomerInstanceFileApiController::class);
        /** @var CustomerInstanceFileApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        $appSettingsProperty = $reflection->getProperty('appSettingsService');
        $appSettingsProperty->setAccessible(true);
        $appSettingsProperty->setValue($controller, $settings);

        $envelopeProperty = $reflection->getProperty('responseEnvelopeFactory');
        $envelopeProperty->setAccessible(true);
        $envelopeProperty->setValue($controller, new ResponseEnvelopeFactory());

        $request = Request::create('/api/instances/77/files/content?path=test.txt');
        $request->headers->set('X-Request-ID', 'req-content-schema');

        $response = $controller->content($request, 77);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('failed', $payload['status']);
        self::assertSame('files_unauthorized', $payload['error_code']);
        self::assertSame('req-content-schema', $payload['request_id']);
    }

}
