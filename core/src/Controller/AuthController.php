<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UserSession;
use App\Repository\UserRepository;
use App\Security\SessionTokenGenerator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SessionTokenGenerator $tokenGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/auth/login', name: 'auth_login', methods: ['POST'])]
    #[Route(path: '/api/v1/auth/login', name: 'auth_login_v1', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $email = (string) ($payload['email'] ?? '');
        $password = (string) ($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            return new JsonResponse(['error' => 'Invalid credentials.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $user = $this->users->findOneByEmail($email);
        if ($user === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['error' => 'Invalid credentials.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $token = $this->tokenGenerator->generateToken();
        $session = new UserSession($user, $this->tokenGenerator->hashToken($token));
        $session->setExpiresAt((new \DateTimeImmutable())->modify('+30 days'));

        $this->entityManager->persist($session);
        $this->auditLogger->log($user, 'session.created', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
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
