<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\LocalAntiAbuseService;
use App\Module\Core\Application\MailService;
use App\Module\Core\Application\MathCaptchaService;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\UserRepository;
use App\Security\SessionTokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class PublicRegistrationController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SessionTokenGenerator $tokenGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly SiteResolver $siteResolver,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly ThemeResolver $themeResolver,
        private readonly MailService $mailService,
        private readonly LocalAntiAbuseService $antiAbuse,
        private readonly MathCaptchaService $captcha,
        private readonly AppSettingsService $settings,
        private readonly UriSigner $uriSigner,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'limiter.public_registration')]
        private readonly RateLimiterFactory $registrationLimiter,
        #[Autowire(service: 'limiter.public_registration_email')]
        private readonly RateLimiterFactory $registrationEmailLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/register', name: 'public_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $session = $request->getSession();
        $antiAbuseData = $this->antiAbuse->registerFormSession($session, 'registration');
        $maintenance = $this->maintenanceService->resolve($request, $site);
        $registrationAllowed = !$maintenance['active'] && $this->settings->isRegistrationEnabled();

        if (!$registrationAllowed) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $form = ['email' => '', 'pow_solution' => '', 'captcha_answer' => ''];
        $errors = [];
        $registered = false;
        $status = Response::HTTP_OK;
        $retryAfter = null;

        $captchaQuestion = null;
        if ($this->settings->isAntiAbuseCaptchaEnabledForRegistration()) {
            $captchaQuestion = $this->captcha->issueChallenge($session, 'registration')['question'];
        }

        if ($request->isMethod('POST')) {
            $payload = $request->request->all();
            $email = trim((string) ($payload['email'] ?? ''));

            if ($this->antiAbuse->isIpLocked($request, 'registration_reject')) {
                return new Response('Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
            }

            if ($this->antiAbuse->isHoneypotTriggered($request)) {
                $this->antiAbuse->log('registration_honeypot', $request, $email);
                return new Response('Invalid request.', Response::HTTP_BAD_REQUEST);
            }

            if (!$this->antiAbuse->verifyMinTime($session, 'registration')) {
                $this->antiAbuse->log('registration_too_fast', $request, $email);
                return new Response('Request rejected.', Response::HTTP_BAD_REQUEST);
            }

            if ($this->settings->isAntiAbusePowEnabledForRegistration() && !$this->antiAbuse->verifyPow($session, 'registration', (string) ($payload['pow_solution'] ?? ''))) {
                $this->antiAbuse->log('registration_pow_failed', $request, $email);
                return new Response('Request rejected.', Response::HTTP_BAD_REQUEST);
            }

            if ($this->settings->isAntiAbuseCaptchaEnabledForRegistration() && !$this->captcha->verifyAnswer($session, 'registration', (string) ($payload['captcha_answer'] ?? ''))) {
                $this->antiAbuse->log('registration_captcha_failed', $request, $email);
                return new Response('Request rejected.', Response::HTTP_BAD_REQUEST);
            }

            $csrf = new CsrfToken('public_register', (string) $request->request->get('_token', ''));
            if (!$this->csrfTokenManager->isTokenValid($csrf)) {
                return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
            }

            $limit = $this->registrationLimiter->create($request->getClientIp() ?? 'public')->consume(1);
            if (!$limit->isAccepted()) {
                $status = Response::HTTP_TOO_MANY_REQUESTS;
                $errors[] = 'Too many registration attempts. Please try again in a moment.';
                $retryAfter = $limit->getRetryAfter();
                $this->antiAbuse->log('registration_rate_limited', $request, $email);
            } else {
                $mailLimit = $this->registrationEmailLimiter->create(strtolower($email))->consume(1);
                if (!$mailLimit->isAccepted()) {
                    $this->antiAbuse->log('registration_email_rate_limited', $request, $email);
                    return new Response('Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS);
                }

                $portalLanguage = strtolower(trim((string) $request->getLocale()));
                $password = (string) ($payload['password'] ?? '');
                $passwordConfirm = (string) ($payload['password_confirm'] ?? '');

                $form = ['email' => $email, 'pow_solution' => '', 'captcha_answer' => ''];

                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Enter a valid email address.';
                }
                if (mb_strlen($password) < 8) {
                    $errors[] = 'Password must be at least 8 characters long.';
                }
                if ($password !== $passwordConfirm) {
                    $errors[] = 'Passwords do not match.';
                }
                if (!in_array($portalLanguage, ['de', 'en'], true)) {
                    $portalLanguage = 'de';
                }
                if ($email !== '' && $this->users->findOneByEmail($email) !== null) {
                    $errors[] = 'An account with this email already exists.';
                }

                if ($errors === []) {
                    $user = new User($email, UserType::Customer);
                    $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
                    $user->setName($email);

                    $now = new \DateTimeImmutable();
                    $ipAddress = $request->getClientIp() ?? 'unknown';
                    $token = $this->tokenGenerator->generateToken();
                    $expiresAt = $now->modify('+1 day');

                    $user->setEmailVerificationTokenHash($this->tokenGenerator->hashToken($token));
                    $user->setEmailVerificationExpiresAt($expiresAt);
                    $user->setEmailVerifiedAt(null);
                    $user->setMemberAccessEnabled(false);
                    $user->recordConsents($ipAddress, $now);

                    $this->entityManager->persist($user);
                    $locale = $portalLanguage === 'en' ? 'en_GB' : 'de_DE';
                    $preferences = new InvoicePreferences($user, $locale, true, true, 'manual', $portalLanguage);
                    $this->entityManager->persist($preferences);
                    $this->entityManager->flush();

                    $verificationUrl = sprintf('%s://%s/register/verify?token=%s&expires=%d', $request->getScheme(), $request->getHttpHost(), rawurlencode($token), $expiresAt->getTimestamp());
                    $verificationUrl = $this->uriSigner->sign($verificationUrl);

                    $this->mailService->sendTemplate($user->getEmail(), 'account_created', [
                        'verification_url' => $verificationUrl,
                        'user_email' => $user->getEmail(),
                    ], $portalLanguage, true);

                    $this->auditLogger->log($user, 'customer.registered', [
                        'user_id' => $user->getId(),
                        'email' => $user->getEmail(),
                        'ip_address' => $ipAddress,
                        'site_id' => $site->getId(),
                    ]);

                    $registered = true;
                } else {
                    $status = Response::HTTP_BAD_REQUEST;
                }
            }
        }

        $templateKey = $this->themeResolver->resolveThemeKey($site);
        $response = new Response($this->twig->render($this->resolveRegisterTemplate($templateKey), [
            'form' => $form,
            'errors' => $errors,
            'registered' => $registered,
            'registrationAllowed' => $registrationAllowed,
            'maintenanceMessage' => $maintenance['message'],
            'siteName' => $site->getName(),
            'template_key' => $templateKey,
            'active_theme' => $templateKey,
            'anti_abuse_nonce' => $antiAbuseData['nonce'],
            'pow_difficulty' => 4,
            'captcha_question' => $captchaQuestion,
        ]), $status);

        if ($retryAfter !== null) {
            $seconds = max(1, $retryAfter->getTimestamp() - time());
            $response->headers->set('Retry-After', (string) $seconds);
        }

        return $response;
    }

    #[Route(path: '/register/verify', name: 'public_register_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        if (!$this->uriSigner->checkRequest($request)) {
            return new Response('Invalid verification link.', Response::HTTP_BAD_REQUEST);
        }

        $expires = (int) $request->query->get('expires', 0);
        if ($expires <= time()) {
            return new Response('Verification link expired or invalid.', Response::HTTP_BAD_REQUEST);
        }

        $token = trim((string) $request->query->get('token', ''));
        if ($token === '') {
            return new Response('Invalid verification link.', Response::HTTP_BAD_REQUEST);
        }

        $hash = $this->tokenGenerator->hashToken($token);
        $user = $this->users->findOneBy(['emailVerificationTokenHash' => $hash]);
        if (!$user instanceof User || $user->getEmailVerificationExpiresAt()?->getTimestamp() < time()) {
            return new Response('Verification link expired or invalid.', Response::HTTP_BAD_REQUEST);
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setEmailVerificationTokenHash(null);
        $user->setEmailVerificationExpiresAt(null);
        $user->setMemberAccessEnabled(true);
        $this->entityManager->flush();

        return new RedirectResponse('/login');
    }

    private function resolveRegisterTemplate(string $templateKey): string
    {
        $themeTemplate = sprintf('themes/%s/auth/register.html.twig', $templateKey);
        if ($this->templateExists($themeTemplate)) {
            return $themeTemplate;
        }

        return 'themes/minimal/auth/register.html.twig';
    }

    private function templateExists(string $template): bool
    {
        $loader = $this->twig->getLoader();

        return method_exists($loader, 'exists') && $loader->exists($template);
    }
}
