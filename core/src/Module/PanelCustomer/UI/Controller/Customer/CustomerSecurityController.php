<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\TwoFactorService;
use App\Module\Core\Domain\Entity\User;
use App\Security\TwoFactorPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/account/security')]
final class CustomerSecurityController
{
    private const REAUTH_WINDOW_SECONDS = 600;
    private const SESSION_REAUTH_KEY = 'last_password_confirmed_at';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly TwoFactorService $twoFactorService,
        private readonly SecretsCrypto $secretsCrypto,
        private readonly TwoFactorPolicy $twoFactorPolicy,
        private readonly AppSettingsService $settingsService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire(service: 'limiter.account_security_confirm')]
        private readonly RateLimiterFactory $confirmLimiter,
        #[Autowire(service: 'limiter.account_security_2fa')]
        private readonly RateLimiterFactory $twoFactorLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_security', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->requireUser($request);
        $this->ensureTotpSecret($user);

        return $this->renderPage($request, $user);
    }

    #[Route(path: '/confirm', name: 'customer_security_confirm', methods: ['POST'])]
    public function confirmPassword(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$this->isCsrfValid($request, 'account_security_confirm')) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $ip = $request->getClientIp() ?? 'public';
        $limiter = $this->confirmLimiter->create($ip . ':' . ($user->getId() ?? 0))->consume(1);
        if (!$limiter->isAccepted()) {
            return $this->renderPage($request, $user, ['Too many confirmation attempts.'], Response::HTTP_TOO_MANY_REQUESTS, ['confirm_required' => true]);
        }

        $currentPassword = (string) $request->request->get('current_password', '');
        if ($currentPassword === '' || !$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return $this->renderPage($request, $user, ['Current password is invalid.'], Response::HTTP_BAD_REQUEST, ['confirm_required' => true]);
        }

        $request->getSession()->set(self::SESSION_REAUTH_KEY, time());

        return $this->renderPage($request, $user, [], Response::HTTP_OK, ['confirm_required' => false]);
    }

    #[Route(path: '/password', name: 'customer_security_password', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        $user = $this->requireUser($request);

        if (!$this->isCsrfValid($request, 'account_security_password')) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->hasFreshReauth($request)) {
            return $this->renderPage($request, $user, ['Please confirm your password first.'], Response::HTTP_FORBIDDEN, ['confirm_required' => true]);
        }

        $currentPassword = (string) $request->request->get('current_password', '');
        $newPassword = (string) $request->request->get('new_password', '');
        $confirmPassword = (string) $request->request->get('new_password_confirm', '');

        $errors = [];
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            $errors[] = 'Current password is invalid.';
        }
        if (mb_strlen($newPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        }

        if ($errors !== []) {
            return $this->renderPage($request, $user, $errors, Response::HTTP_BAD_REQUEST);
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->flush();

        return $this->renderPage($request, $user, [], Response::HTTP_OK, ['success' => 'password_changed_success']);
    }

    #[Route(path: '/2fa/enable', name: 'customer_security_2fa_enable', methods: ['POST'])]
    public function enableTwoFactor(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$this->isCsrfValid($request, 'account_security_enable_2fa')) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->consumeTwoFactorLimit($request, $user)) {
            return $this->renderPage($request, $user, ['Too many authentication attempts.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $otp = trim((string) $request->request->get('otp', ''));

        $secret = $user->getTotpSecret($this->secretsCrypto) ?? $this->ensureTotpSecret($user);

        if ($secret === null || !$this->twoFactorService->verifyCode($secret, $otp)) {
            return $this->renderPage($request, $user, ['Invalid authentication code.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setTotpEnabled(true);
        $codes = $this->twoFactorService->generateRecoveryCodes();
        $user->setTotpRecoveryCodes($this->twoFactorService->hashRecoveryCodes($codes));

        $this->auditLogger->log($user, 'two_factor.enabled', [
            'user_id' => $user->getId(),
            'context' => 'customer_security',
        ]);

        $this->entityManager->flush();

        return $this->renderPage($request, $user, [], Response::HTTP_OK, [
            'recovery_codes' => $codes,
            'success' => 'two_factor_enabled_success',
        ]);
    }

    #[Route(path: '/2fa/disable', name: 'customer_security_2fa_disable', methods: ['POST'])]
    public function disableTwoFactor(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$this->isCsrfValid($request, 'account_security_disable_2fa')) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->hasFreshReauth($request)) {
            return $this->renderPage($request, $user, ['Please confirm your password first.'], Response::HTTP_FORBIDDEN, ['confirm_required' => true]);
        }

        if (!$this->consumeTwoFactorLimit($request, $user)) {
            return $this->renderPage($request, $user, ['Too many authentication attempts.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $otp = trim((string) $request->request->get('otp', ''));
        $recoveryCode = trim((string) $request->request->get('recovery_code', ''));

        if (!$this->verifyTwoFactorChallenge($user, $otp, $recoveryCode)) {
            return $this->renderPage($request, $user, ['Invalid authentication code.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setTotpEnabled(false);
        $user->setTotpSecret(null);
        $user->clearTotpRecoveryCodes();

        $this->auditLogger->log($user, 'two_factor.disabled', [
            'user_id' => $user->getId(),
            'context' => 'customer_security',
        ]);

        $this->entityManager->flush();

        return $this->renderPage($request, $user, [], Response::HTTP_OK, [
            'success' => 'two_factor_disabled_success',
        ]);
    }

    #[Route(path: '/2fa/recovery', name: 'customer_security_2fa_recovery', methods: ['POST'])]
    public function regenerateRecoveryCodes(Request $request): Response
    {
        $user = $this->requireUser($request);
        if (!$this->isCsrfValid($request, 'account_security_recovery_2fa')) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->hasFreshReauth($request)) {
            return $this->renderPage($request, $user, ['Please confirm your password first.'], Response::HTTP_FORBIDDEN, ['confirm_required' => true]);
        }

        if (!$this->consumeTwoFactorLimit($request, $user)) {
            return $this->renderPage($request, $user, ['Too many authentication attempts.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $otp = trim((string) $request->request->get('otp', ''));
        $recoveryCode = trim((string) $request->request->get('recovery_code', ''));

        if (!$this->verifyTwoFactorChallenge($user, $otp, $recoveryCode)) {
            return $this->renderPage($request, $user, ['Invalid authentication code.'], Response::HTTP_BAD_REQUEST);
        }

        $codes = $this->twoFactorService->generateRecoveryCodes();
        $user->setTotpRecoveryCodes($this->twoFactorService->hashRecoveryCodes($codes));

        $this->auditLogger->log($user, 'two_factor.recovery_regenerated', [
            'user_id' => $user->getId(),
            'context' => 'customer_security',
        ]);

        $this->entityManager->flush();

        return $this->renderPage($request, $user, [], Response::HTTP_OK, [
            'recovery_codes' => $codes,
            'success' => 'two_factor_recovery_regenerated',
        ]);
    }

    private function requireUser(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }

    private function ensureTotpSecret(User $user): ?string
    {
        if ($user->isTotpEnabled()) {
            return $user->getTotpSecret($this->secretsCrypto);
        }

        $secret = $user->getTotpSecret($this->secretsCrypto);
        if ($secret !== null) {
            return $secret;
        }

        $secret = $this->twoFactorService->generateSecret();
        $user->setTotpSecret($secret, $this->secretsCrypto);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $secret;
    }

    private function verifyTwoFactorChallenge(User $user, string $otp, string $recoveryCode): bool
    {
        if (!$user->isTotpEnabled()) {
            return false;
        }

        $secret = $user->getTotpSecret($this->secretsCrypto);
        if ($secret !== null && $otp !== '' && $this->twoFactorService->verifyCode($secret, $otp)) {
            return true;
        }

        if ($recoveryCode === '') {
            return false;
        }

        $index = $this->twoFactorService->verifyRecoveryCode($recoveryCode, $user->getTotpRecoveryCodes());
        if ($index === null) {
            return false;
        }

        $codes = $user->getTotpRecoveryCodes();
        unset($codes[$index]);
        $user->setTotpRecoveryCodes(array_values($codes));

        $this->auditLogger->log($user, 'two_factor.recovery_used', [
            'user_id' => $user->getId(),
            'context' => 'customer_security',
        ]);

        return true;
    }

    private function hasFreshReauth(Request $request): bool
    {
        $confirmedAt = (int) $request->getSession()->get(self::SESSION_REAUTH_KEY, 0);

        return $confirmedAt > 0 && (time() - $confirmedAt) <= self::REAUTH_WINDOW_SECONDS;
    }

    private function isCsrfValid(Request $request, string $tokenId): bool
    {
        $token = new CsrfToken($tokenId, (string) $request->request->get('_token', ''));

        return $this->csrfTokenManager->isTokenValid($token);
    }

    private function consumeTwoFactorLimit(Request $request, User $user): bool
    {
        $key = sprintf('%s:%d', $request->getClientIp() ?? 'public', $user->getId() ?? 0);

        return $this->twoFactorLimiter->create($key)->consume(1)->isAccepted();
    }

    /**
     * @param string[] $errors
     * @param array{recovery_codes?: string[], success?: string, confirm_required?: bool} $overrides
     */
    private function renderPage(Request $request, User $user, array $errors = [], int $status = Response::HTTP_OK, array $overrides = []): Response
    {
        $secret = $user->isTotpEnabled() ? null : $user->getTotpSecret($this->secretsCrypto);
        $issuer = $this->settingsService->getBrandingName();
        $otpAuth = $secret !== null ? $this->twoFactorService->getOtpAuthUri($issuer, $user->getEmail(), $secret) : null;

        return new Response($this->twig->render('public/account/security.html.twig', [
            'activeNav' => 'security',
            'pageTitle' => 'Sicherheit',
            'errors' => $errors,
            'confirmRequired' => $overrides['confirm_required'] ?? !$this->hasFreshReauth($request),
            'twoFactor' => [
                'enabled' => $user->isTotpEnabled(),
                'required' => $this->twoFactorPolicy->isRequired($user),
                'secret' => $secret,
                'otpauth' => $otpAuth,
                'qr' => $otpAuth !== null ? '/2fa/qr?v=' . rawurlencode(substr(hash('sha256', $otpAuth), 0, 12)) : null,
                'recovery_codes' => $overrides['recovery_codes'] ?? [],
                'success' => $overrides['success'] ?? null,
            ],
        ]), $status);
    }
}
