<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Repository\UserSessionRepository;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class SessionAuthenticator
{
    public const ADMIN_SESSION_COOKIE = 'easywi_session';
    public const CUSTOMER_SESSION_COOKIE = 'easywi_customer_session';

    public function __construct(
        private readonly UserSessionRepository $sessionRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettingsService $settingsService,
    ) {
    }

    public function authenticate(Request $request): ?User
    {
        $path = $request->getPathInfo();
        $token = $this->extractBearerToken($request);
        $candidateTokens = [];
        if ($token !== null) {
            $candidateTokens[] = $token;
        } else {
            if (!$this->isAdminPath($path) && !$this->isResellerPath($path)) {
                $customerToken = $this->extractCookieToken($request, self::CUSTOMER_SESSION_COOKIE);
                if ($customerToken !== null) {
                    $candidateTokens[] = $customerToken;
                }
            }

            $adminToken = $this->extractCookieToken($request, self::ADMIN_SESSION_COOKIE);
            if ($adminToken !== null) {
                $candidateTokens[] = $adminToken;
            }
        }

        if ($candidateTokens === []) {
            return null;
        }

        $session = null;
        foreach (array_unique($candidateTokens) as $candidateToken) {
            $tokenHash = hash('sha256', $candidateToken);
            try {
                $session = $this->sessionRepository->findActiveByTokenHash($tokenHash);
            } catch (TableNotFoundException) {
                return null;
            }

            if ($session !== null) {
                break;
            }
        }

        if ($session === null) {
            return null;
        }

        $idleMinutes = $this->settingsService->getSessionIdleTimeoutMinutes();
        if ($idleMinutes > 0) {
            $lastUsed = $session->getLastUsedAt() ?? $session->getCreatedAt();
            $idleCutoff = (new \DateTimeImmutable())->modify(sprintf('-%d minutes', $idleMinutes));
            if ($lastUsed <= $idleCutoff) {
                $session->revoke();
                $this->entityManager->persist($session);
                $this->auditLogger->log($session->getUser(), 'session.expired', [
                    'session_id' => $session->getId(),
                    'user_id' => $session->getUser()->getId(),
                    'reason' => 'idle_timeout',
                ]);
                return null;
            }
        }

        $session->setLastUsedAt(new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->auditLogger->log($session->getUser(), 'session.used', [
            'session_id' => $session->getId(),
            'user_id' => $session->getUser()->getId(),
        ]);

        return $session->getUser();
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if ($header === null) {
            return null;
        }

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));
        return $token !== '' ? $token : null;
    }

    private function extractCookieToken(Request $request, string $cookieName): ?string
    {
        $token = $request->cookies->get($cookieName);
        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);
        return $token !== '' ? $token : null;
    }

    private function isAdminPath(string $path): bool
    {
        return str_starts_with($path, '/admin')
            || str_starts_with($path, '/api/admin')
            || str_starts_with($path, '/api/v1/admin');
    }

    private function isResellerPath(string $path): bool
    {
        return str_starts_with($path, '/reseller')
            || str_starts_with($path, '/api/reseller')
            || str_starts_with($path, '/api/v1/reseller');
    }
}
