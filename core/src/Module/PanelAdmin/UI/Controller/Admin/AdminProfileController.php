<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\UserRepository;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/profile')]
final class AdminProfileController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_profile', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        return $this->renderPage($admin);
    }

    #[Route(path: '', name: 'admin_profile_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        $name = trim((string) $request->request->get('name', ''));
        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');
        $passwordConfirm = (string) $request->request->get('password_confirm', '');
        $signature = trim((string) $request->request->get('signature', ''));

        $errors = [];
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if ($email !== '' && $email !== $admin->getEmail()) {
            $existing = $this->userRepository->findOneByEmail($email);
            if ($existing !== null && $existing->getId() !== $admin->getId()) {
                $errors[] = 'An account with this email already exists.';
            }
        }

        if ($password !== '') {
            if (mb_strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters long.';
            }

            if ($password !== $passwordConfirm) {
                $errors[] = 'Passwords do not match.';
            }
        }

        if ($errors !== []) {
            return $this->renderPage($admin, [
                'name' => $name,
                'email' => $email,
                'signature' => $signature,
            ], $errors, Response::HTTP_BAD_REQUEST);
        }

        $admin->setName($name);

        if ($email !== '') {
            $admin->setEmail($email);
        }

        if ($password !== '') {
            $admin->setPasswordHash($this->passwordHasher->hashPassword($admin, $password));
        }

        $admin->setAdminSignature($signature);

        $this->auditLogger->log($admin, 'admin.profile.updated', [
            'admin_id' => $admin->getId(),
            'name' => $admin->getName(),
            'email' => $admin->getEmail(),
            'password_updated' => $password !== '',
            'signature_updated' => $signature !== '',
        ]);

        $this->entityManager->flush();

        return $this->renderPage($admin, [
            'name' => $admin->getName(),
            'email' => $admin->getEmail(),
            'success' => true,
            'signature' => $admin->getAdminSignature(),
        ]);
    }

    private function renderPage(User $admin, array $overrides = [], array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('admin/profile/index.html.twig', [
            'activeNav' => 'profile',
            'form' => array_merge([
                'email' => $admin->getEmail(),
                'name' => $admin->getName(),
                'success' => false,
                'signature' => $admin->getAdminSignature(),
            ], $overrides),
            'errors' => $errors,
        ]), $status);
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }
}
