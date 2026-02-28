<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Core\Application\AppSettingsService;
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
        private readonly AppSettingsService $settingsService,
        private readonly IdentifierHasher $identifierHasher,
    ) {
    }

    public function finalizeLogin(Request $request, User $user, string $redirectPath, string $context): Response
    {
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
