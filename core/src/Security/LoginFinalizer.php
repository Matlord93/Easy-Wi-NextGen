<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class LoginFinalizer
{
    public function __construct(
        private readonly SessionTokenGenerator $tokenGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AppSettingsService $settingsService,
        private readonly IdentifierHasher $identifierHasher,
        private readonly UserSessionRepository $sessionRepository,
    ) {
    }

    public function finalizeLogin(Request $request, User $user, string $redirectPath, string $context): Response
    {
        $maxSessions = $this->settingsService->getMaxConcurrentSessions();
        if ($maxSessions > 0) {
            $activeSessions = $this->sessionRepository->findActiveByUser($user);
            $overflow = count($activeSessions) - $maxSessions + 1;
            for ($i = 0; $i < $overflow; $i++) {
                $oldest = $activeSessions[$i];
                $oldest->revoke();
                $this->entityManager->persist($oldest);
                $this->auditLogger->log($user, 'session.revoked', [
                    'session_id' => $oldest->getId(),
                    'reason' => 'max_concurrent_sessions',
                    'context' => $context,
                ]);
            }
        }

        $token = $this->tokenGenerator->generateToken();
        $session = new UserSession($user, $this->tokenGenerator->hashToken($token));
        $absoluteMinutes = $this->settingsService->getSessionAbsoluteTimeoutMinutes();
        $session->setExpiresAt((new \DateTimeImmutable())->modify(sprintf('+%d minutes', $absoluteMinutes)));
        $session->setLastUsedAt(new \DateTimeImmutable());

        $this->entityManager->persist($session);
        $this->auditLogger->log($user, 'session.created', [
            'user_id' => $user->getId(),
            'context' => $context,
        ]);
        $this->auditLogger->log($user, 'auth.login_success', [
            'ip_address' => $request->getClientIp() ?? 'public',
            'channel' => 'auth',
            'identifier_hash' => $this->identifierHasher->hash($user->getEmail()),
            'context' => $context,
        ]);
        $this->entityManager->flush();

        $request->getSession()->migrate(true);

        $request->getSession()->set('last_login_at', time());
        $request->getSession()->set('last_password_confirmed_at', time());

        $response = new RedirectResponse($redirectPath);
        $cookieName = $user->isAdmin() || in_array('ROLE_RESELLER', $user->getRoles(), true)
            ? SessionAuthenticator::ADMIN_SESSION_COOKIE
            : SessionAuthenticator::CUSTOMER_SESSION_COOKIE;

        $response->headers->setCookie(
            Cookie::create($cookieName, $token)
                ->withPath('/')
                ->withSecure(true)
                ->withHttpOnly(true)
                ->withSameSite('strict')
                ->withExpires((new \DateTimeImmutable())->modify(sprintf('+%d minutes', $absoluteMinutes)))
        );

        return $response;
    }
}
