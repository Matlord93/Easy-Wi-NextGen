<?php

declare(strict_types=1);

namespace App\Module\Core\UI\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

interface ResponseEnvelopeFactoryInterface
{
    /**
     * @param array<string, mixed> $extra
     */
    public function success(Request $request, ?string $jobId, string $message, int $statusCode = JsonResponse::HTTP_ACCEPTED, array $extra = []): JsonResponse;
    /**
     * @param array<string, mixed> $extra
     */
    public function error(Request $request, string $message, string $errorCode, int $statusCode, ?int $retryAfter = null, array $extra = []): JsonResponse;
}
