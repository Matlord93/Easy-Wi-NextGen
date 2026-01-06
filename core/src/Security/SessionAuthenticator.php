<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserSessionRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class SessionAuthenticator
{
    public function __construct(
        private readonly UserSessionRepository $sessionRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function authenticate(Request $request): ?User
    {
        $token = $this->extractBearerToken($request) ?? $this->extractCookieToken($request);
        if ($token === null) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $session = $this->sessionRepository->findActiveByTokenHash($tokenHash);
        if ($session === null) {
            return null;
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

    private function extractCookieToken(Request $request): ?string
    {
        $token = $request->cookies->get('easywi_session');
        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);
        return $token !== '' ? $token : null;
    }
}
