<?php
declare(strict_types=1);
namespace App\Tests\Module\Core\Application;
use App\Module\Core\Application\ApiErrorResponseFactory;
use App\Module\Core\Application\TraceContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final class ApiErrorResponseFactoryTest extends TestCase
{
    public function testMapsHttpExceptionToDocumentedEnvelope(): void
    {
        $response = (new ApiErrorResponseFactory(new TraceContext()))->create(Request::create('/api/v1/item'), new NotFoundHttpException('Item not found.'));
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('NOT_FOUND', $data['error']['code']);
        self::assertSame($data['error']['request_id'], $response->headers->get('X-Request-ID'));
    }
    public function testHidesInternalExceptionMessage(): void
    {
        $response = (new ApiErrorResponseFactory(new TraceContext()))->create(Request::create('/api/v1/item'), new \RuntimeException('database password leaked'));
        self::assertStringNotContainsString('password', (string) $response->getContent());
    }
}
