<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Module\Core\Domain\Entity\UserSession;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Application\TwoFactorService;
use App\Module\Core\Application\SecretsCrypto;
use App\Repository\UserRepository;
use App\Security\SessionTokenGenerator;
use App\Module\Core\Application\AuditLogger;
use App\Module\Setup\Application\InstallerService;
use App\Security\TwoFactorPolicy;
use App\Module\Core\Application\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
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
        private readonly SessionTokenGenerator $tokenGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly SiteResolver $siteResolver,
        private readonly InstallerService $installerService,
        private readonly TwoFactorService $twoFactorService,
        private readonly SecretsCrypto $secretsCrypto,
        private readonly TwoFactorPolicy $twoFactorPolicy,
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

        $form = [
            'email' => '',
        ];
        $errors = [];
        $status = Response::HTTP_OK;
        $retryAfter = null;
        $requiresTwoFactor = false;
        $twoFactorEnabled = false;
        $enrollmentRequired = false;

        if ($request->isMethod('POST')) {
            $payload = $request->request->all();
            $email = trim((string) ($payload['email'] ?? ''));
            $password = (string) ($payload['password'] ?? '');
            $otp = trim((string) ($payload['otp'] ?? ''));
            $recoveryCode = trim((string) ($payload['recovery_code'] ?? ''));

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

                $this->auditLogger->log(null, 'auth.login.rate_limited', [
                    'ip_address' => $ipAddress,
                    'identifier' => $identifier,
                    'context' => 'public_login',
                    'retry_after' => $retryAfter?->format(DATE_ATOM),
                ]);
            }

            if ($errors === []) {
                $user = $this->users->findOneByEmail($email);
                if ($user === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
                    $errors[] = 'Invalid credentials.';
                    $status = Response::HTTP_UNAUTHORIZED;
                    $this->auditLogger->log($user, 'auth.login.failed', [
                        'ip_address' => $ipAddress,
                        'identifier' => $identifier,
                        'reason' => 'invalid_credentials',
                        'context' => 'public_login',
                    ]);
                } else {
                    $requiresTwoFactor = $this->twoFactorPolicy->isRequired($user);
                    $twoFactorEnabled = $user->isTotpEnabled();
                    $enrollmentRequired = $requiresTwoFactor && !$twoFactorEnabled;

                    if ($twoFactorEnabled) {
                        $secret = $user->getTotpSecret($this->secretsCrypto);
                        if ($secret === null) {
                            $errors[] = 'Two-factor authentication is not configured. Contact support.';
                            $status = Response::HTTP_FORBIDDEN;
                        } elseif ($otp === '' && $recoveryCode === '') {
                            $errors[] = 'Enter your authentication code.';
                            $status = Response::HTTP_UNAUTHORIZED;
                        } elseif ($otp !== '' && $this->twoFactorService->verifyCode($secret, $otp)) {
                            // ok
                        } elseif ($recoveryCode !== '') {
                            $index = $this->twoFactorService->verifyRecoveryCode($recoveryCode, $user->getTotpRecoveryCodes());
                            if ($index === null) {
                                $errors[] = 'Invalid authentication code.';
                                $status = Response::HTTP_UNAUTHORIZED;
                            } else {
                                $codes = $user->getTotpRecoveryCodes();
                                unset($codes[$index]);
                                $user->setTotpRecoveryCodes(array_values($codes));
                                $this->auditLogger->log($user, 'auth.login.recovery_used', [
                                    'user_id' => $user->getId(),
                                    'context' => 'public_login',
                                ]);
                            }
                        } else {
                            $errors[] = 'Invalid authentication code.';
                            $status = Response::HTTP_UNAUTHORIZED;
                        }
                    } elseif ($requiresTwoFactor) {
                        $this->auditLogger->log($user, 'auth.login.enrollment_required', [
                            'user_id' => $user->getId(),
                            'context' => 'public_login',
                        ]);
                    }

                    if ($errors !== []) {
                        $this->auditLogger->log($user, 'auth.login.failed', [
                            'ip_address' => $ipAddress,
                            'identifier' => $identifier,
                            'reason' => 'two_factor_failed',
                            'context' => 'public_login',
                        ]);
                    }

                    if ($errors !== []) {
                        // fall through
                    } else {
                        $token = $this->tokenGenerator->generateToken();
                        $session = new UserSession($user, $this->tokenGenerator->hashToken($token));
                        $session->setExpiresAt((new \DateTimeImmutable())->modify('+30 days'));
                        $session->setLastUsedAt(new \DateTimeImmutable());

                        $this->entityManager->persist($session);
                        $this->auditLogger->log($user, 'session.created', [
                            'user_id' => $user->getId(),
                            'email' => $user->getEmail(),
                        ]);
                        $this->auditLogger->log($user, 'auth.login.success', [
                            'ip_address' => $ipAddress,
                            'identifier' => $identifier,
                            'context' => 'public_login',
                        ]);
                        $this->entityManager->flush();

                        $redirectPath = match (true) {
                            $enrollmentRequired && $user->isAdmin() => '/admin/profile',
                            $enrollmentRequired => '/profile/security',
                            default => match ($user->getType()) {
                                UserType::Admin, UserType::Superadmin => '/admin',
                                UserType::Reseller => '/reseller/customers',
                                default => '/dashboard',
                            },
                        };
                    $response = new RedirectResponse($redirectPath);
                    $response->headers->setCookie(
                        Cookie::create('easywi_session', $token)
                            ->withPath('/')
                            ->withSecure($request->isSecure())
                            ->withHttpOnly(true)
                            ->withSameSite('lax')
                            ->withExpires((new \DateTimeImmutable())->modify('+30 days'))
                    );

                    return $response;
                    }
                }
            } else {
                $status = Response::HTTP_BAD_REQUEST;
            }
        }

        $response = new Response($this->twig->render('public/auth/login.html.twig', [
            'form' => $form,
            'errors' => $errors,
            'siteName' => $site->getName(),
            'requiresTwoFactor' => $requiresTwoFactor,
            'twoFactorEnabled' => $twoFactorEnabled,
            'enrollmentRequired' => $enrollmentRequired,
        ]), $status);

        if ($retryAfter !== null) {
            $seconds = max(1, $retryAfter->getTimestamp() - time());
            $response->headers->set('Retry-After', (string) $seconds);
        }

        return $response;
    }
}
