<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\User;
use App\Module\Setup\Application\InstallerService;
use App\Repository\UserRepository;
use App\Security\LoginFinalizer;
use App\Security\PostLoginRedirectResolver;
use App\Security\TwoFactorPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PublicLoginController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoginFinalizer $loginFinalizer,
        private readonly AuditLogger $auditLogger,
        private readonly SiteResolver $siteResolver,
        private readonly InstallerService $installerService,
        private readonly TwoFactorPolicy $twoFactorPolicy,
        private readonly PostLoginRedirectResolver $redirectResolver,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly ThemeResolver $themeResolver,
        private readonly AppSettingsService $appSettings,
        #[Autowire(service: 'limiter.public_login_ip')]
        private readonly RateLimiterFactory $loginIpLimiter,
        #[Autowire(service: 'limiter.public_login_identifier')]
        private readonly RateLimiterFactory $loginIdentifierLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/login', name: 'public_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if (!$this->installerService->isLocked()) {
            return new RedirectResponse('/install');
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);

        $form = ['email' => ''];
        $errors = [];
        $status = Response::HTTP_OK;
        $retryAfter = null;

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $form['email'] = $email;

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address.';
            }

            if ($password === '') {
                $errors[] = 'Enter your password.';
            }

            $ipAddress = $request->getClientIp() ?? 'public';
            $identifier = $email !== '' ? mb_strtolower($email) : 'unknown';

            $ipLimit = $this->loginIpLimiter->create($ipAddress)->consume(1);
            $idLimit = $this->loginIdentifierLimiter->create($identifier)->consume(1);
            if (!$ipLimit->isAccepted() || !$idLimit->isAccepted()) {
                $status = Response::HTTP_TOO_MANY_REQUESTS;
                $errors = ['Too many login attempts. Please try again in a moment.'];
                foreach ([$ipLimit, $idLimit] as $limit) {
                    if (!$limit->isAccepted() && $limit->getRetryAfter() !== null) {
                        if ($retryAfter === null || $limit->getRetryAfter() > $retryAfter) {
                            $retryAfter = $limit->getRetryAfter();
                        }
                    }
                }
            }

            if ($errors === []) {
                $user = $this->users->findOneByEmail($email);
                if ($user === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
                    $status = Response::HTTP_UNAUTHORIZED;
                    $errors[] = 'Invalid credentials.';
                    $this->auditLogger->log($user, 'auth.login.failed', [
                        'ip_address' => $ipAddress,
                        'identifier' => $identifier,
                        'reason' => 'invalid_credentials',
                        'context' => 'public_login',
                    ]);
                } else {
                    $requiresTwoFactor = $this->twoFactorPolicy->isRequired($user);
                    if ($user->isTotpEnabled()) {
                        $session = $request->getSession();
                        $session->set('auth_pending_user_id', $user->getId());
                        $session->set('auth_pending_since', time());
                        $requestedTarget = (string) ($request->request->get('target', $request->query->get('target', $session->get(PostLoginRedirectResolver::SESSION_TARGET_KEY, ''))));
                        $session->set('auth_pending_target_path', $requestedTarget);
                        $session->remove('auth_2fa_attempts');
                        $session->remove('auth_2fa_locked_until');

                        return new RedirectResponse('/2fa');
                    }

                    if ($requiresTwoFactor) {
                        $redirectPath = $user->isAdmin() ? '/admin/profile' : '/account/security';

                        return $this->loginFinalizer->finalizeLogin($request, $user, $redirectPath, 'public_login');
                    }

                    $redirectPath = $this->redirectResolver->resolve($user, $request);

                    return $this->loginFinalizer->finalizeLogin($request, $user, $redirectPath, 'public_login');
                }
            } else {
                $status = Response::HTTP_BAD_REQUEST;
            }
        }

        $templateKey = $this->themeResolver->resolveThemeKey($site);
        $registrationAllowed = !$maintenance['active'] && $this->appSettings->isRegistrationEnabled();

        $response = new Response($this->twig->render($this->resolveLoginTemplate($templateKey), [
            'form' => $form,
            'errors' => $errors,
            'siteName' => $site->getName(),
            'registrationAllowed' => $registrationAllowed,
            'loginTarget' => (string) ($request->query->get('target', $request->request->get('target', ''))),
            'template_key' => $templateKey,
            'active_theme' => $templateKey,
        ]), $status);

        if ($retryAfter !== null) {
            $seconds = max(1, $retryAfter->getTimestamp() - time());
            $response->headers->set('Retry-After', (string) $seconds);
        }

        return $response;
    }
    private function resolveLoginTemplate(string $templateKey): string
    {
        $themeTemplate = sprintf('themes/%s/auth/login.html.twig', $templateKey);
        if ($this->templateExists($themeTemplate)) {
            return $themeTemplate;
        }

        return 'themes/minimal/auth/login.html.twig';
    }

    private function templateExists(string $template): bool
    {
        $loader = $this->twig->getLoader();

        return method_exists($loader, 'exists') && $loader->exists($template);
    }
}
