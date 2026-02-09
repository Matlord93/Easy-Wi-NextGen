<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Domain\Entity\UserSession;
use App\Module\Core\Application\TwoFactorService;
use App\Module\Core\Application\SecretsCrypto;
use App\Repository\UserRepository;
use App\Security\SessionTokenGenerator;
use App\Module\Core\Application\AuditLogger;
use App\Module\Setup\Application\InstallerService;
use App\Security\TwoFactorPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SessionTokenGenerator $tokenGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly InstallerService $installerService,
        private readonly TwoFactorService $twoFactorService,
        private readonly SecretsCrypto $secretsCrypto,
        private readonly TwoFactorPolicy $twoFactorPolicy,
        #[Autowire(service: 'limiter.public_login_ip')]
        private readonly RateLimiterFactory $loginIpLimiter,
        #[Autowire(service: 'limiter.public_login_identifier')]
        private readonly RateLimiterFactory $loginIdentifierLimiter,
    ) {
    }

    #[Route(path: '/api/auth/login', name: 'auth_login', methods: ['POST'])]
    #[Route(path: '/api/v1/auth/login', name: 'auth_login_v1', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        if (!$this->installerService->isLocked()) {
            return new JsonResponse(['error' => 'Installation incomplete.'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        $payload = $request->toArray();
        $email = (string) ($payload['email'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        $otp = trim((string) ($payload['otp'] ?? ''));
        $recoveryCode = trim((string) ($payload['recovery_code'] ?? ''));

        $ipAddress = $request->getClientIp() ?? 'api';
        $identifier = $email !== '' ? mb_strtolower($email) : 'unknown';
        $retryAfter = null;

        $ipLimit = $this->loginIpLimiter->create($ipAddress)->consume(1);
        $idLimit = $this->loginIdentifierLimiter->create($identifier)->consume(1);
        if (!$ipLimit->isAccepted() || !$idLimit->isAccepted()) {
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
                'context' => 'api_login',
                'retry_after' => $retryAfter?->format(DATE_ATOM),
            ]);

            $response = new JsonResponse(['error' => 'Too many login attempts.'], JsonResponse::HTTP_TOO_MANY_REQUESTS);
            if ($retryAfter !== null) {
                $seconds = max(1, $retryAfter->getTimestamp() - time());
                $response->headers->set('Retry-After', (string) $seconds);
            }
            return $response;
        }

        if ($email === '' || $password === '') {
            return new JsonResponse(['error' => 'Invalid credentials.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $user = $this->users->findOneByEmail($email);
        if ($user === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->auditLogger->log($user, 'auth.login.failed', [
                'ip_address' => $ipAddress,
                'identifier' => $identifier,
                'reason' => 'invalid_credentials',
                'context' => 'api_login',
            ]);
            return new JsonResponse(['error' => 'Invalid credentials.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $requiresTwoFactor = $this->twoFactorPolicy->isRequired($user);
        if ($requiresTwoFactor && !$user->isTotpEnabled()) {
            $this->auditLogger->log($user, 'auth.login.enrollment_required', [
                'user_id' => $user->getId(),
                'context' => 'api_login',
            ]);
            return new JsonResponse(['error' => 'Two-factor enrollment required.'], JsonResponse::HTTP_FORBIDDEN);
        }

        if ($user->isTotpEnabled()) {
            $secret = $user->getTotpSecret($this->secretsCrypto);
            if ($secret === null) {
                return new JsonResponse(['error' => 'Two-factor authentication is not configured.'], JsonResponse::HTTP_FORBIDDEN);
            }

            if ($otp === '' && $recoveryCode === '') {
                $this->auditLogger->log($user, 'auth.login.failed', [
                    'ip_address' => $ipAddress,
                    'identifier' => $identifier,
                    'reason' => 'two_factor_missing',
                    'context' => 'api_login',
                ]);
                return new JsonResponse(['error' => 'Two-factor authentication required.'], JsonResponse::HTTP_UNAUTHORIZED);
            }

            $recoveryIndex = null;
            $valid = $otp !== '' && $this->twoFactorService->verifyCode($secret, $otp);
            if (!$valid && $recoveryCode !== '') {
                $recoveryIndex = $this->twoFactorService->verifyRecoveryCode($recoveryCode, $user->getTotpRecoveryCodes());
                $valid = $recoveryIndex !== null;
            }

            if (!$valid) {
                $this->auditLogger->log($user, 'auth.login.failed', [
                    'ip_address' => $ipAddress,
                    'identifier' => $identifier,
                    'reason' => 'two_factor_failed',
                    'context' => 'api_login',
                ]);
                return new JsonResponse(['error' => 'Invalid authentication code.'], JsonResponse::HTTP_UNAUTHORIZED);
            }

            if ($recoveryIndex !== null) {
                $codes = $user->getTotpRecoveryCodes();
                unset($codes[$recoveryIndex]);
                $user->setTotpRecoveryCodes(array_values($codes));
                $this->auditLogger->log($user, 'auth.login.recovery_used', [
                    'user_id' => $user->getId(),
                    'context' => 'api_login',
                ]);
            }
        }

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
            'context' => 'api_login',
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'type' => $user->getType()->value,
            ],
        ]);
    }
}
