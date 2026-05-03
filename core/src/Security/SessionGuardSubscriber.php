<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Setup\Application\InstallerService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
        private readonly TwoFactorPolicy $twoFactorPolicy,
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
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->installerService->isLocked()) {
            return;
        }

        $request = $event->getRequest();
        $path = $this->normalizePath($request->getPathInfo());
        $routeName = $request->attributes->get('_route');

        $isPublic = $this->isPublicPath($path) || $this->isPublicRoute($routeName);
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

        if ($this->twoFactorPolicy->isRequired($user) && !$user->isTotpEnabled()) {
            if ($this->isTwoFactorEnrollmentPath($path)) {
                return;
            }

            $event->setResponse($this->twoFactorRequiredResponse($request));
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
            || $path === '/2fa'
            || $path === '/2fa_check'
            || $path === '/2fa/qr'
            || $path === '/profile/security'
            || str_starts_with($path, '/profile/security/')
            || $path === '/register'
            || $path === '/register/verify'
            || $path === '/contact'
            || $path === '/kontakt'
            || $path === '/status'
            || $path === '/changelog'
            || $path === '/downloads'
            || $path === '/blog'
            || str_starts_with($path, '/blog/')
            || str_starts_with($path, '/docs')
            || str_starts_with($path, '/servers')
            || str_starts_with($path, '/pages/')
            || str_starts_with($path, '/_')
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/css/')
            || str_starts_with($path, '/js/')
            || str_starts_with($path, '/images/');
    }

    private function isPublicRoute(mixed $routeName): bool
    {
        if (!is_string($routeName)) {
            return false;
        }

        return str_starts_with($routeName, 'public_cms_')
            || str_starts_with($routeName, 'public_forum_')
            || str_starts_with($routeName, 'public_cookie_')
            || str_starts_with($routeName, 'public_robots')
            || str_starts_with($routeName, 'public_sitemap')
            || $routeName === 'public_theme_preview'
            || $routeName === 'public_kontakt'
            || $routeName === 'customer_files_health';
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

        $path = $this->normalizePath($request->getPathInfo());
        if ($this->isLoginRedirectPath($path)) {
            $request->getSession()->set(PostLoginRedirectResolver::SESSION_TARGET_KEY, $path);

            return new RedirectResponse('/login?target=' . rawurlencode($path));
        }

        return new Response('Unauthorized.', Response::HTTP_UNAUTHORIZED);
    }


    private function isLoginRedirectPath(string $path): bool
    {
        return $path === '/profile' || $path === '/profile/edit' || $path === '/account/security' || str_starts_with($path, '/account/security/');
    }

    private function forbiddenResponse(Request $request): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return new JsonResponse(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
    }

    private function twoFactorRequiredResponse(Request $request): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return new JsonResponse(['error' => 'Two-factor authentication required.'], Response::HTTP_FORBIDDEN);
        }

        return new Response('Two-factor authentication required.', Response::HTTP_FORBIDDEN);
    }

    private function isTwoFactorEnrollmentPath(string $path): bool
    {
        return $path === '/profile/security'
            || str_starts_with($path, '/profile/security/')
            || $path === '/account/security'
            || str_starts_with($path, '/account/security/')
            || $path === '/admin/profile'
            || str_starts_with($path, '/admin/profile/')
            || $path === '/logout';
    }

    private function normalizePath(string $path): string
    {
        if ($path === '/') {
            return $path;
        }

        return rtrim($path, '/');
    }
}
