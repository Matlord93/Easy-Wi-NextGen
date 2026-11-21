<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ApiErrorResponseFactory
{
    public function __construct(private TraceContext $traceContext, private TranslatorInterface $translator)
    {
    }

    public function create(Request $request, \Throwable $exception): JsonResponse
    {
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof \InvalidArgumentException ? 400 : 500);
        $csrf = $exception instanceof HttpExceptionInterface && in_array($status, [401, 403], true)
            && ('csrf' === ($exception->getHeaders()['WWW-Authenticate'] ?? null) || 1 === preg_match('/csrf|invalid token/i', $exception->getMessage()));
        if ($csrf) {
            $status = 403;
        }
        $code = match ($status) {
            400 => 'VALIDATION_FAILED', 401 => 'UNAUTHORIZED', 403 => $csrf ? 'FORM_EXPIRED' : 'FORBIDDEN',
            404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 409 => 'CONFLICT',
            429 => 'RATE_LIMITED', default => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
        };
        $messageKey = match (true) {
            $csrf => 'portal_problem_form_expired_message',
            401 === $status => 'portal_problem_login_required_message',
            403 === $status && '' === trim($exception->getMessage()) => 'portal_problem_forbidden_message',
            $status >= 500 => 'portal_problem_unavailable_message',
            default => null,
        };
        $message = null === $messageKey
            ? $this->translator->trans(trim($exception->getMessage()) ?: 'portal_problem_unavailable_message')
            : $this->translator->trans($messageKey, [], 'portal');
        $requestId = $this->traceContext->requestId($request);
        $headers = [TraceContext::REQUEST_HEADER => $requestId];
        if ($csrf) {
            $headers['X-EasyWi-Problem'] = 'form_expired';
        }

        return new JsonResponse(['error' => ['code' => $code, 'message' => $message, 'request_id' => $requestId, 'details' => (object) []]], $status, $headers);
    }
}
