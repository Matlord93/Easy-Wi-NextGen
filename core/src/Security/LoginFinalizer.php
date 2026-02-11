<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\UserSession;
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
    ) {
    }

    public function finalizeLogin(Request $request, User $user, string $redirectPath, string $context): Response
    {
        $token = $this->tokenGenerator->generateToken();
        $session = new UserSession($user, $this->tokenGenerator->hashToken($token));
        $session->setExpiresAt((new \DateTimeImmutable())->modify('+30 days'));
        $session->setLastUsedAt(new \DateTimeImmutable());

        $this->entityManager->persist($session);
        $this->auditLogger->log($user, 'session.created', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'context' => $context,
        ]);
        $this->auditLogger->log($user, 'auth.login.success', [
            'ip_address' => $request->getClientIp() ?? 'public',
            'identifier' => mb_strtolower($user->getEmail()),
            'context' => $context,
        ]);
        $this->entityManager->flush();

        $request->getSession()->set('last_login_at', time());
        $request->getSession()->set('last_password_confirmed_at', time());

        $response = new RedirectResponse($redirectPath);
        $response->headers->setCookie(
            Cookie::create(SessionAuthenticator::ADMIN_SESSION_COOKIE, $token)
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite('lax')
                ->withExpires((new \DateTimeImmutable())->modify('+30 days'))
        );

        return $response;
    }
}

