<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\UserSessionRepository;
use App\Security\SessionAuthenticator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LogoutController
{
    public function __construct(
        private readonly UserSessionRepository $sessionRepository,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/logout', name: 'public_logout', methods: ['GET', 'POST'])]
    public function logout(Request $request): Response
    {
        $cookieNames = [
            SessionAuthenticator::ADMIN_SESSION_COOKIE,
            SessionAuthenticator::CUSTOMER_SESSION_COOKIE,
        ];

        foreach ($cookieNames as $cookieName) {
            $token = $request->cookies->get($cookieName);
            if (!is_string($token) || $token === '') {
                continue;
            }

            $tokenHash = hash('sha256', $token);
            $session = $this->sessionRepository->findActiveByTokenHash($tokenHash);
            if ($session === null) {
                continue;
            }

            $session->revoke();
            $this->entityManager->persist($session);
            $this->auditLogger->log($session->getUser(), 'session.revoked', [
                'session_id' => $session->getId(),
                'user_id' => $session->getUser()->getId(),
            ]);
        }

        $this->entityManager->flush();

        $response = new RedirectResponse('/login');
        foreach ($cookieNames as $cookieName) {
            $response->headers->setCookie(
                Cookie::create($cookieName)
                    ->withValue('')
                    ->withPath('/')
                    ->withSecure($request->isSecure())
                    ->withHttpOnly(true)
                    ->withSameSite('lax')
                    ->withExpires((new \DateTimeImmutable('-1 day')))
            );
        }

        return $response;
    }
}
