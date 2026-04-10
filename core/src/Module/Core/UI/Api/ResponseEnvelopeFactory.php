<?php

declare(strict_types=1);

namespace App\Module\Core\UI\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ResponseEnvelopeFactory
{
    /**
     * @param array<string, mixed> $extra
     */
    public function success(
        Request $request,
        string $jobId,
        string $message,
        int $statusCode = JsonResponse::HTTP_ACCEPTED,
        array $extra = [],
    ): JsonResponse {
        return new JsonResponse(array_merge([
            'job_id' => $jobId,
            'status' => 'queued',
            'message' => $message,
            'request_id' => $this->resolveRequestId($request),
        ], $extra), $statusCode);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function error(
        Request $request,
        string $message,
        string $errorCode,
        int $statusCode,
        ?int $retryAfter = null,
        array $extra = [],
    ): JsonResponse {
        $payload = array_merge([
            'status' => 'failed',
            'message' => $message,
            'error_code' => $errorCode,
            'request_id' => $this->resolveRequestId($request),
        ], $extra);

        if ($retryAfter !== null) {
            $payload['retry_after'] = $retryAfter;
        }

        return new JsonResponse($payload, $statusCode);
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = trim((string) ($request->headers->get('X-Request-ID') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $traceId = trim((string) ($request->attributes->get('request_id') ?? ''));

        return $traceId;
    }
}
