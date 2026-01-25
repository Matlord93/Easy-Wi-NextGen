<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\Application\AdminSshKeyService;
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
        private readonly AdminSshKeyService $sshKeyService,
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
        $sshKey = trim((string) $request->request->get('ssh_key', ''));

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

        $currentSshKey = $admin->getAdminSshPublicKey();
        $canManageSshKey = $this->canManageSshKey($admin);
        if ($sshKey !== '') {
            if (!$canManageSshKey) {
                $errors[] = 'SSH key access is not enabled for this account yet. Please contact a super admin to enable it.';
            }
            if ($currentSshKey !== null) {
                $errors[] = 'An SSH public key is already stored for this account. Please contact a super admin to change it.';
            } elseif (!$this->isValidSshPublicKey($sshKey)) {
                $errors[] = 'Enter a valid SSH public key.';
            }
        }

        if ($errors !== []) {
            return $this->renderPage($admin, [
                'name' => $name,
                'email' => $email,
                'signature' => $signature,
                'ssh_key' => $currentSshKey ?? $sshKey,
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

        $sshKeyQueued = false;
        if ($sshKey !== '' && $currentSshKey === null && $canManageSshKey) {
            if ($admin->getType() === UserType::Superadmin) {
                try {
                    $admin->setAdminSshPublicKeyPending($sshKey);
                    $this->sshKeyService->storeKey($admin, $sshKey);
                } catch (\Throwable) {
                    return $this->renderPage($admin, [
                        'name' => $name,
                        'email' => $email,
                        'signature' => $signature,
                        'ssh_key' => $sshKey,
                    ], ['Unable to store the SSH key on the server. Please contact a super admin.'], Response::HTTP_BAD_REQUEST);
                }
                $sshKeyQueued = true;
            } else {
                $admin->setAdminSshPublicKeyPending($sshKey);
                $sshKeyQueued = true;
            }
        }

        $this->auditLogger->log($admin, 'admin.profile.updated', [
            'admin_id' => $admin->getId(),
            'name' => $admin->getName(),
            'email' => $admin->getEmail(),
            'password_updated' => $password !== '',
            'signature_updated' => $signature !== '',
            'ssh_key_queued' => $sshKeyQueued,
        ]);

        $this->entityManager->flush();

        return $this->renderPage($admin, [
            'name' => $admin->getName(),
            'email' => $admin->getEmail(),
            'success' => true,
            'signature' => $admin->getAdminSignature(),
            'ssh_key' => $admin->getAdminSshPublicKey(),
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
                'ssh_key' => $admin->getAdminSshPublicKey() ?? '',
                'ssh_key_set' => $admin->getAdminSshPublicKey() !== null,
                'ssh_key_pending' => $admin->getAdminSshPublicKeyPending() !== null,
                'ssh_key_pending_value' => $admin->getAdminSshPublicKeyPending(),
                'ssh_key_enabled' => $this->canManageSshKey($admin),
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

    private function isValidSshPublicKey(string $sshKey): bool
    {
        $sshKey = trim($sshKey);
        if ($sshKey === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(ssh-(?:rsa|ed25519)|ecdsa-sha2-nistp(?:256|384|521)|sk-ssh-ed25519@openssh\\.com|sk-ecdsa-sha2-nistp256@openssh\\.com)\\s+[A-Za-z0-9+\\/=]+(?:\\s+.+)?$/',
            $sshKey,
        );
    }

    private function canManageSshKey(User $admin): bool
    {
        return $admin->getType() === UserType::Superadmin || $admin->isAdminSshKeyEnabled();
    }
}
