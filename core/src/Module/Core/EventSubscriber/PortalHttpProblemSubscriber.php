<?php

declare(strict_types=1);

namespace App\Module\Core\EventSubscriber;

use App\Infrastructure\Http\RequestActor;
use App\Security\PortalAccessResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class PortalHttpProblemSubscriber implements EventSubscriberInterface
{
    public function __construct(private PortalAccessResponse $responses)
    {
    }

    /** @return array{'kernel.exception': array{0: string, 1: int}, 'kernel.response': array{0: string, 1: int}} */
    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', 5],
            KernelEvents::RESPONSE => ['onResponse', 20],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();
        if (!$event->isMainRequest() || !$exception instanceof HttpExceptionInterface || !$this->isPortalRequest($request)) {
            return;
        }
        $status = $exception->getStatusCode();
        $headers = $exception->getHeaders();
        $isCsrf = 'csrf' === ($headers['WWW-Authenticate'] ?? null) || 'form_expired' === $this->legacyCode($exception->getMessage());
        if ((401 === $status || 403 === $status) && $isCsrf) {
            unset($headers['WWW-Authenticate']);
            $event->setResponse($this->responses->problem($request, 403, 'form_expired', headers: $headers));

            return;
        }
        if (401 === $status && null === RequestActor::user($request)) {
            $event->setResponse($this->responses->signIn($request));

            return;
        }
        $code = match ($status) {
            401, 403 => 'Checkout requires a customer account.' === $exception->getMessage() ? 'customer_required' : 'forbidden',
            404 => in_array($exception->getMessage(), ['This module is not enabled.', 'The integrated CMS is disabled.'], true) ? 'module_disabled' : 'not_found',
            405 => 'method_not_allowed',
            429 => 'rate_limited',
            503 => 'unavailable',
            default => null,
        };
        if (null !== $code) {
            $event->setResponse($this->responses->problem($request, 401 === $status ? 403 : $status, $code, headers: $headers));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $status = $response->getStatusCode();
        if (!$event->isMainRequest() || !in_array($status, [401, 403, 404], true)
            || !$this->isPortalRequest($request) || $response->headers->has('X-EasyWi-Problem')) {
            return;
        }

        $body = $response->getContent();
        if (false === $body || strlen($body) > 80) {
            return;
        }
        $code = $this->legacyCode($body);
        if (401 === $status && 'forbidden' === $code && null === RequestActor::user($request)) {
            $event->setResponse($this->responses->signIn($request));

            return;
        }
        if (null !== $code) {
            $event->setResponse($this->responses->problem($request, 401 === $status ? 403 : $status, $code, headers: $response->headers->all()));
        }
    }

    private function legacyCode(string $message): ?string
    {
        return match (mb_strtolower(rtrim(trim($message), '.'))) {
            'csrf', 'invalid csrf token', 'invalid csrf', 'ungültiges csrf-token' => 'form_expired',
            'unauthorized', 'nicht autorisiert', 'error_security_unauthorized', 'forbidden', 'zugriff verweigert' => 'forbidden',
            'not found', 'nicht gefunden' => 'not_found',
            default => null,
        };
    }

    private function isPortalRequest(Request $request): bool
    {
        $path = $request->getPathInfo();

        return !str_starts_with($path, '/api/') && !str_starts_with($path, '/agent/') && !str_starts_with($path, '/install')
            && !str_starts_with($path, '/billing/webhook/');
    }
}
