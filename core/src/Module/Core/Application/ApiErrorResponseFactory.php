<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class ApiErrorResponseFactory
{
    public function __construct(private TraceContext $traceContext)
    {
    }

    public function create(Request $request, \Throwable $exception): JsonResponse
    {
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof \InvalidArgumentException ? 400 : 500);
        $code = match ($status) {
            400 => 'VALIDATION_FAILED', 401 => 'UNAUTHORIZED', 403 => 'FORBIDDEN',
            404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 409 => 'CONFLICT',
            429 => 'RATE_LIMITED', default => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
        };
        $message = $status >= 500 ? 'The request could not be completed.' : trim($exception->getMessage());
        if ('' === $message) {
            $message = 'The request could not be completed.';
        }
        $requestId = $this->traceContext->requestId($request);

        return new JsonResponse(['error' => ['code' => $code, 'message' => $message, 'request_id' => $requestId, 'details' => (object) []]], $status, [TraceContext::REQUEST_HEADER => $requestId]);
    }
}
