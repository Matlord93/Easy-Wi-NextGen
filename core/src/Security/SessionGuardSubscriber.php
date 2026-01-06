<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SessionGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly SessionAuthenticator $sessionAuthenticator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 15],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if ($this->isPublicPath($path) || !$this->requiresSession($path)) {
            return;
        }

        $user = $this->sessionAuthenticator->authenticate($request);
        if ($user === null) {
            $event->setResponse($this->unauthorizedResponse($request));
            return;
        }

        $request->attributes->set('current_user', $user);
    }

    private function requiresSession(string $path): bool
    {
        if (str_starts_with($path, '/api/')) {
            return true;
        }

        return $this->isUiRoute($path);
    }

    private function isPublicPath(string $path): bool
    {
        return str_starts_with($path, '/api/auth/')
            || $path === '/login'
            || str_starts_with($path, '/_')
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/css/')
            || str_starts_with($path, '/js/')
            || str_starts_with($path, '/images/');
    }

    private function isUiRoute(string $path): bool
    {
        return !str_starts_with($path, '/api/');
    }

    private function unauthorizedResponse(Request $request): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return new JsonResponse(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        return new Response('Unauthorized.', Response::HTTP_UNAUTHORIZED);
    }
}
