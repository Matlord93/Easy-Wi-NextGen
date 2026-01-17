<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\UserRepository;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AdminUserController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $users,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/admin/users', name: 'admin_create_user', methods: ['POST'])]
    #[Route(path: '/api/v1/admin/users', name: 'admin_create_user_v1', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $request->toArray();
        $email = (string) ($payload['email'] ?? '');
        $password = (string) ($payload['password'] ?? '');
        $typeValue = (string) ($payload['type'] ?? UserType::Customer->value);

        if ($email === '' || $password === '') {
            return new JsonResponse(['error' => 'Email and password are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $type = UserType::tryFrom($typeValue) ?? UserType::Customer;

        if ($this->users->findOneByEmail($email) !== null) {
            return new JsonResponse(['error' => 'Email already exists.'], JsonResponse::HTTP_CONFLICT);
        }

        $user = new User($email, $type);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        if (!$type->isAdmin()) {
            $preferences = new InvoicePreferences($user, 'de_DE', true, true, 'manual', 'de');
            $this->entityManager->persist($preferences);
        }
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'user.created', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'type' => $user->getType()->value,
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'type' => $user->getType()->value,
        ], JsonResponse::HTTP_CREATED);
    }
}
