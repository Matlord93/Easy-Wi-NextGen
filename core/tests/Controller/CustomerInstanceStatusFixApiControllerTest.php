<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceFileApiController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomerInstanceFileApiControllerTest extends TestCase
{
    public function testListUnauthorizedReturnsErrorEnvelope(): void
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('isCustomerDataManagerEnabled')->willReturn(true);

        $controller = $this->newControllerWithSettings($settings);

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

        $controller = $this->newControllerWithSettings($settings);

        $request = Request::create('/api/instances/77/files/content?path=test.txt');
        $request->headers->set('X-Request-ID', 'req-content-schema');

        $response = $controller->content($request, 77);
        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('failed', $payload['status']);
        self::assertSame('files_unauthorized', $payload['error_code']);
        self::assertSame('req-content-schema', $payload['request_id']);
    }

    private function newControllerWithSettings(AppSettingsService $settings): CustomerInstanceFileApiController
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string|\Stringable $id): string => (string) $id);

        $reflection = new \ReflectionClass(CustomerInstanceFileApiController::class);
        /** @var CustomerInstanceFileApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'appSettingsService' => $settings,
            'responseEnvelopeFactory' => new ResponseEnvelopeFactory(),
            'translator' => $translator,
        ] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($controller, $value);
        }

        return $controller;
    }
}
