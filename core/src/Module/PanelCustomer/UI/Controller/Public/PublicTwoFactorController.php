<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Application\TwoFactorService;
use App\Module\Core\Domain\Entity\User;
use App\Repository\UserRepository;
use App\Security\LoginFinalizer;
use App\Security\PostLoginRedirectResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class PublicTwoFactorController
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly UserRepository $users,
        private readonly TwoFactorService $twoFactorService,
        private readonly SecretsCrypto $secretsCrypto,
        private readonly LoginFinalizer $loginFinalizer,
        private readonly PostLoginRedirectResolver $redirectResolver,
        private readonly AuditLogger $auditLogger,
        private readonly SiteResolver $siteResolver,
        private readonly ThemeResolver $themeResolver,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'limiter.public_2fa_check')]
        private readonly RateLimiterFactory $twoFactorLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/2fa', name: 'public_2fa', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pendingUser = $this->resolvePendingUser($request);
        if (!$pendingUser instanceof User) {
            return new RedirectResponse('/login');
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $templateKey = $this->themeResolver->resolveThemeKey($site);

        return new Response($this->twig->render($this->resolveTemplate($templateKey), [
            'errors' => [],
            'siteName' => $site->getName(),
            'template_key' => $templateKey,
            'active_theme' => $templateKey,
        ]));
    }

    #[Route(path: '/2fa_check', name: 'public_2fa_check', methods: ['POST'])]
    public function check(Request $request): Response
    {
        $pendingUser = $this->resolvePendingUser($request);
        if (!$pendingUser instanceof User) {
            return new RedirectResponse('/login');
        }

        $session = $request->getSession();
        $lockedUntil = (int) $session->get('auth_2fa_locked_until', 0);
        if ($lockedUntil > time()) {
            return new Response('Too many attempts. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $csrf = new CsrfToken('public_2fa_check', (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($csrf)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $limiterKey = sprintf('%s:%d', $request->getClientIp() ?? 'public', $pendingUser->getId() ?? 0);
        $limit = $this->twoFactorLimiter->create($limiterKey)->consume(1);
        if (!$limit->isAccepted()) {
            return new Response('Too many attempts. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        $code = trim((string) $request->request->get('otp', ''));
        $secret = $pendingUser->getTotpSecret($this->secretsCrypto);

        if ($secret === null || !$this->twoFactorService->verifyCode($secret, $code, 1)) {
            $attempts = (int) $session->get('auth_2fa_attempts', 0) + 1;
            $session->set('auth_2fa_attempts', $attempts);

            if ($attempts >= self::MAX_ATTEMPTS) {
                $session->set('auth_2fa_locked_until', time() + 300);
                $session->set('auth_2fa_attempts', 0);
            }

            $this->auditLogger->log($pendingUser, 'auth.login.failed', [
                'reason' => 'two_factor_failed',
                'context' => 'public_2fa_check',
            ]);

            return new RedirectResponse('/2fa');
        }

        $target = $this->redirectResolver->resolve($pendingUser, $request);
        $this->clearPending($session);

        return $this->loginFinalizer->finalizeLogin($request, $pendingUser, $target, 'public_2fa_check');
    }

    private function resolvePendingUser(Request $request): ?User
    {
        $session = $request->getSession();
        $pendingUserId = $session->get('auth_pending_user_id');
        $pendingSince = (int) $session->get('auth_pending_since', 0);
        if (!is_int($pendingUserId) || $pendingUserId <= 0 || $pendingSince <= 0 || (time() - $pendingSince) > 600) {
            $this->clearPending($session);
            return null;
        }

        $user = $this->users->find($pendingUserId);
        if (!$user instanceof User || !$user->isTotpEnabled()) {
            $this->clearPending($session);
            return null;
        }

        return $user;
    }

    private function clearPending($session): void
    {
        $session->remove('auth_pending_user_id');
        $session->remove('auth_pending_since');
        $session->remove('auth_pending_target_path');
        $session->remove('auth_2fa_attempts');
        $session->remove('auth_2fa_locked_until');
    }

    private function resolveTemplate(string $templateKey): string
    {
        $themeTemplate = sprintf('themes/%s/auth/two_factor.html.twig', $templateKey);
        if ($this->templateExists($themeTemplate)) {
            return $themeTemplate;
        }

        return 'themes/minimal/auth/two_factor.html.twig';
    }

    private function templateExists(string $template): bool
    {
        $loader = $this->twig->getLoader();

        return method_exists($loader, 'exists') && $loader->exists($template);
    }
}
