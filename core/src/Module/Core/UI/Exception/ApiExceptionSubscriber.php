<?php

declare(strict_types=1);

namespace App\Module\Core\UI\Exception;

use App\Module\Core\Application\TraceContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TraceContext $traceContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        $code = $this->resolveCode($throwable);
        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : $code->httpStatus();

        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => $code->value,
                'message' => $this->resolveMessage($throwable, $code),
                'request_id' => $this->traceContext->requestId($request),
            ],
        ], $status));
    }

    private function resolveCode(\Throwable $throwable): ApiErrorCode
    {
        if ($throwable instanceof \JsonException) {
            return ApiErrorCode::InvalidJson;
        }

        if (!$throwable instanceof HttpExceptionInterface) {
            return ApiErrorCode::InternalError;
        }

        return match ($throwable->getStatusCode()) {
            400 => ApiErrorCode::ValidationFailed,
            401 => ApiErrorCode::Unauthorized,
            403 => ApiErrorCode::Forbidden,
            404 => ApiErrorCode::NotFound,
            405 => ApiErrorCode::MethodNotAllowed,
            409 => ApiErrorCode::Conflict,
            default => ApiErrorCode::InternalError,
        };
    }

    private function resolveMessage(\Throwable $throwable, ApiErrorCode $code): string
    {
        $message = trim($throwable->getMessage());
        if ($message !== '') {
            return $message;
        }

        return match ($code) {
            ApiErrorCode::InvalidJson => 'Invalid JSON payload.',
            ApiErrorCode::ValidationFailed => 'Validation failed.',
            ApiErrorCode::Unauthorized => 'Unauthorized.',
            ApiErrorCode::Forbidden => 'Forbidden.',
            ApiErrorCode::NotFound => 'Not found.',
            ApiErrorCode::MethodNotAllowed => 'Method not allowed.',
            ApiErrorCode::Conflict => 'Conflict.',
            ApiErrorCode::InternalError => 'Internal server error.',
        };
    }
}

