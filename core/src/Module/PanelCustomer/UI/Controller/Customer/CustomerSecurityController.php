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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/profile/security')]
final class CustomerSecurityController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly TwoFactorService $twoFactorService,
        private readonly SecretsCrypto $secretsCrypto,
        private readonly TwoFactorPolicy $twoFactorPolicy,
        private readonly AppSettingsService $settingsService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_security', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->requireUser($request);
        $this->ensureTotpSecret($user);

        return $this->renderPage($user);
    }

    #[Route(path: '/2fa/enable', name: 'customer_security_2fa_enable', methods: ['POST'])]
    public function enableTwoFactor(Request $request): Response
    {
        $user = $this->requireUser($request);
        $otp = trim((string) $request->request->get('otp', ''));

        $secret = $user->getTotpSecret($this->secretsCrypto) ?? $this->ensureTotpSecret($user);

        if ($secret === null || !$this->twoFactorService->verifyCode($secret, $otp)) {
            return $this->renderPage($user, ['Invalid authentication code.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setTotpEnabled(true);
        $codes = $this->twoFactorService->generateRecoveryCodes();
        $user->setTotpRecoveryCodes($this->twoFactorService->hashRecoveryCodes($codes));

        $this->auditLogger->log($user, 'two_factor.enabled', [
            'user_id' => $user->getId(),
            'context' => 'customer_security',
        ]);

        $this->entityManager->flush();

        return $this->renderPage($user, [], Response::HTTP_OK, [
            'recovery_codes' => $codes,
            'success' => 'two_factor_enabled_success',
        ]);
    }

    #[Route(path: '/2fa/disable', name: 'customer_security_2fa_disable', methods: ['POST'])]
    public function disableTwoFactor(Request $request): Response
    {
        $user = $this->requireUser($request);
        $otp = trim((string) $request->request->get('otp', ''));
        $recoveryCode = trim((string) $request->request->get('recovery_code', ''));

        if (!$this->verifyTwoFactorChallenge($user, $otp, $recoveryCode)) {
            return $this->renderPage($user, ['Invalid authentication code.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setTotpEnabled(false);
        $user->setTotpSecret(null);
        $user->clearTotpRecoveryCodes();

        $this->auditLogger->log($user, 'two_factor.disabled', [
            'user_id' => $user->getId(),
            'context' => 'customer_security',
        ]);

        $this->entityManager->flush();

        return $this->renderPage($user, [], Response::HTTP_OK, [
            'success' => 'two_factor_disabled_success',
        ]);
    }

    #[Route(path: '/2fa/recovery', name: 'customer_security_2fa_recovery', methods: ['POST'])]
    public function regenerateRecoveryCodes(Request $request): Response
    {
        $user = $this->requireUser($request);
        $otp = trim((string) $request->request->get('otp', ''));
        $recoveryCode = trim((string) $request->request->get('recovery_code', ''));

        if (!$this->verifyTwoFactorChallenge($user, $otp, $recoveryCode)) {
            return $this->renderPage($user, ['Invalid authentication code.'], Response::HTTP_BAD_REQUEST);
        }

        $codes = $this->twoFactorService->generateRecoveryCodes();
        $user->setTotpRecoveryCodes($this->twoFactorService->hashRecoveryCodes($codes));

        $this->auditLogger->log($user, 'two_factor.recovery_regenerated', [
            'user_id' => $user->getId(),
            'context' => 'customer_security',
        ]);

        $this->entityManager->flush();

        return $this->renderPage($user, [], Response::HTTP_OK, [
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

    /**
     * @param string[] $errors
     * @param array{recovery_codes?: string[], success?: string} $overrides
     */
    private function renderPage(User $user, array $errors = [], int $status = Response::HTTP_OK, array $overrides = []): Response
    {
        $secret = $user->isTotpEnabled() ? null : $user->getTotpSecret($this->secretsCrypto);
        $issuer = $this->settingsService->getBrandingName();
        $otpAuth = $secret !== null ? $this->twoFactorService->getOtpAuthUri($issuer, $user->getEmail(), $secret) : null;

        return new Response($this->twig->render('customer/security/index.html.twig', [
            'activeNav' => 'security',
            'errors' => $errors,
            'twoFactor' => [
                'enabled' => $user->isTotpEnabled(),
                'required' => $this->twoFactorPolicy->isRequired($user),
                'secret' => $secret,
                'otpauth' => $otpAuth,
                'qr' => $otpAuth !== null ? sprintf('https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=%s', rawurlencode($otpAuth)) : null,
                'recovery_codes' => $overrides['recovery_codes'] ?? [],
                'success' => $overrides['success'] ?? null,
            ],
        ]), $status);
    }
}
