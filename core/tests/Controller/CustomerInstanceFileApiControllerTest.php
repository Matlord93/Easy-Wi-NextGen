<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceFileApiController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(CustomerInstanceFileApiController::class)]
final class CustomerInstanceFileApiControllerTest extends TestCase
{
    public function testListUnauthorizedReturnsErrorEnvelope(): void
    {
        $settings = $this->createStub(AppSettingsService::class);
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
        $settings = $this->createStub(AppSettingsService::class);
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

    public function testFileMetadataKeepsOptionalFlagsAndHidesTechnicalDetails(): void
    {
        $controller = $this->newControllerWithSettings($this->createStub(AppSettingsService::class));
        $method = new \ReflectionMethod($controller, 'normalizeEntry');
        $entry = ['name' => 'server.cfg', 'size' => '1024', 'mode' => '0644', 'modified_at' => '2026-01-01',
            'is_dir' => false, 'is_symlink' => true, 'metadata_available' => false,
            'error_code' => 'STAT_FAILED', 'error_message' => '/private/storage failed', 'link_target' => '/private/target'];
        $safe = $method->invoke($controller, $entry, false);
        self::assertSame(1024, $safe['size']);
        self::assertTrue($safe['is_symlink']);
        self::assertFalse($safe['metadata_available']);
        self::assertSame('STAT_FAILED', $safe['error_code']);
        self::assertArrayNotHasKey('error_message', $safe);
        self::assertArrayNotHasKey('link_target', $safe);
        $technical = $method->invoke($controller, $entry, true);
        self::assertSame('/private/target', $technical['link_target']);
        self::assertSame('/private/storage failed', $technical['error_message']);
    }

    public function testMalformedMetadataFieldsUseSerializableDefaults(): void
    {
        $controller = $this->newControllerWithSettings($this->createStub(AppSettingsService::class));
        $result = (new \ReflectionMethod($controller, 'normalizeEntry'))->invoke(
            $controller,
            ['name' => [], 'size' => [], 'mode' => [], 'modified_at' => [], 'error_code' => []]
        );
        self::assertSame('', $result['name']);
        self::assertSame(0, $result['size']);
        self::assertSame('????', $result['mode']);
        self::assertSame('', $result['modified_at']);
        self::assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testDiagnosticsAcceptsOptionalListingFieldsAndRedactsWarningPaths(): void
    {
        $controller = $this->newControllerWithSettings($this->createStub(AppSettingsService::class));
        $method = new \ReflectionMethod($controller, 'runDiagnosticsProbe');
        $minimal = $method->invoke($controller, 'agent', static fn (): array =>
            ['root_path' => '/private/root', 'path' => '', 'entries' => []], false);
        self::assertSame('ok', $minimal['status']);
        self::assertSame('', $minimal['root_path']);
        self::assertSame(0, $minimal['total']);
        self::assertFalse($minimal['truncated']);
        self::assertSame([], $minimal['warnings']);
        $withWarning = $method->invoke($controller, 'agent', static fn (): array =>
            ['root_path' => '/private/root', 'path' => '', 'entries' => [],
                'warnings' => [['code' => 'STAT_FAILED', 'message' => 'Failed', 'path' => '/private/root/server.cfg']],
                'total' => 20, 'truncated' => true], false);
        self::assertSame(20, $withWarning['total']);
        self::assertTrue($withWarning['truncated']);
        self::assertSame('server.cfg', $withWarning['warnings'][0]['path']);
    }

    public function testJsonEncodingFailureStillReturnsAnErrorEnvelope(): void
    {
        $controller = $this->newControllerWithSettings($this->createStub(AppSettingsService::class));
        $response = (new \ReflectionMethod($controller, 'jsonResponse'))->invoke(
            $controller,
            Request::create('/api/instances/77/files'),
            ['invalid' => NAN]
        );
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('files_json_encode_failed', json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['error_code']);
    }

    private function newControllerWithSettings(AppSettingsService $settings): CustomerInstanceFileApiController
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string|\Stringable $id): string => (string) $id);

        $reflection = new \ReflectionClass(CustomerInstanceFileApiController::class);
        /** @var CustomerInstanceFileApiController $controller */
        $controller = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'appSettingsService' => $settings,
            'responseEnvelopeFactory' => new ResponseEnvelopeFactory(),
            'logger' => new \Psr\Log\NullLogger(),
            'translator' => $translator,
        ] as $property => $value) {
            $prop = $reflection->getProperty($property);
            $prop->setValue($controller, $value);
        }

        return $controller;
    }
}
