<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Setup\Application\InstallerService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SessionGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SessionAuthenticator $sessionAuthenticator,
        private readonly PortalAccessPolicy $portalAccessPolicy,
        private readonly InstallerService $installerService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 15],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // ✅ never guard CLI commands
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            return;
        }

        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->installerService->isLocked()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        $isPublic = $this->isPublicPath($path);
        $requiresSession = $this->requiresSession($path);

        if ($isPublic || !$requiresSession) {
            $user = $this->sessionAuthenticator->authenticate($request);
            if ($user !== null) {
                $request->attributes->set('current_user', $user);
            }
            return;
        }

        $user = $this->sessionAuthenticator->authenticate($request);
        if ($user === null) {
            $event->setResponse($this->unauthorizedResponse($request));
            return;
        }

        $request->attributes->set('current_user', $user);

        if (!$this->portalAccessPolicy->isAllowed($user, $path)) {
            $event->setResponse($this->forbiddenResponse($request));
            return;
        }
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
            || str_starts_with($path, '/api/v1/auth/')
            || str_starts_with($path, '/agent/')
            || str_starts_with($path, '/api/v1/agent/')
            || $path === '/'
            || str_starts_with($path, '/install')
            || $path === '/logout'
            || $path === '/login'
            || $path === '/register'
            || $path === '/status'
            || $path === '/changelog'
            || $path === '/downloads'
            || str_starts_with($path, '/docs')
            || str_starts_with($path, '/servers')
            || str_starts_with($path, '/pages/')
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

    private function forbiddenResponse(Request $request): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return new JsonResponse(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
    }
}
