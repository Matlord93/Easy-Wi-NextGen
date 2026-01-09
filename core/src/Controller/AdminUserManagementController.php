<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\InvoicePreferences;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\InvoicePreferencesRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/users')]
final class AdminUserManagementController
{
    private const LOCALE_OPTIONS = [
        'de_DE' => 'Deutsch (DE)',
        'en_GB' => 'English (UK)',
        'en_US' => 'English (US)',
    ];

    private const PORTAL_LANGUAGE_OPTIONS = [
        'de' => 'Deutsch',
        'en' => 'English',
    ];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly InvoicePreferencesRepository $invoicePreferencesRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $this->requireAdmin($request);
        if ($actor === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->renderIndex($request));
    }

    #[Route(path: '', name: 'admin_users_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $this->requireAdmin($request);
        if ($actor === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');
        $passwordConfirm = (string) $request->request->get('password_confirm', '');
        $typeValue = (string) $request->request->get('type', UserType::Customer->value);

        $errors = $this->validateUserPayload($email, $password, $passwordConfirm, $typeValue, null, true);
        if ($errors !== []) {
            return new Response($this->renderIndex($request, $errors, [
                'createForm' => [
                    'email' => $email,
                    'type' => $typeValue,
                ],
            ]), Response::HTTP_BAD_REQUEST);
        }

        $type = UserType::from($typeValue);
        $user = new User($email, $type);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        if ($type !== UserType::Admin) {
            $preferences = new InvoicePreferences($user, 'de_DE', true, true, 'manual', 'de');
            $this->entityManager->persist($preferences);
        }
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'admin.user.created', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'type' => $user->getType()->value,
        ]);

        return new RedirectResponse('/admin/users?created=' . $user->getId());
    }

    #[Route(path: '/{id}/details', name: 'admin_users_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $this->requireAdmin($request);
        if ($actor === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            return new Response('User not found.', Response::HTTP_NOT_FOUND);
        }

        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');
        $passwordConfirm = (string) $request->request->get('password_confirm', '');
        $typeValue = (string) $request->request->get('type', $user->getType()->value);

        $errors = $this->validateUserPayload($email, $password, $passwordConfirm, $typeValue, $user, false);
        if ($errors !== []) {
            return new Response($this->renderIndex($request, $errors), Response::HTTP_BAD_REQUEST);
        }

        if ($email !== '') {
            $user->setEmail($email);
        }
        $user->setType(UserType::from($typeValue));
        if ($password !== '') {
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        }

        $this->auditLogger->log($actor, 'admin.user.updated', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'type' => $user->getType()->value,
            'password_updated' => $password !== '',
        ]);

        $this->entityManager->flush();

        return new RedirectResponse('/admin/users?updated=' . $user->getId());
    }

    #[Route(path: '/{id}/preferences', name: 'admin_user_preferences_update', methods: ['POST'])]
    public function updatePreferences(Request $request, int $id): Response
    {
        $actor = $this->requireAdmin($request);
        if ($actor === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $user = $this->userRepository->find($id);
        if ($user === null) {
            return new Response('User not found.', Response::HTTP_NOT_FOUND);
        }

        $portalLanguage = (string) $request->request->get('portal_language', '');
        $locale = (string) $request->request->get('locale', '');

        $errors = [];
        if (!array_key_exists($portalLanguage, self::PORTAL_LANGUAGE_OPTIONS)) {
            $errors[] = 'Select a valid portal language.';
        }

        if (!array_key_exists($locale, self::LOCALE_OPTIONS)) {
            $errors[] = 'Select a valid invoice locale.';
        }

        if ($errors !== []) {
            return new Response($this->renderIndex($request, $errors), Response::HTTP_BAD_REQUEST);
        }

        $preferences = $this->invoicePreferencesRepository->findOneByCustomer($user);
        if ($preferences === null) {
            $preferences = new InvoicePreferences($user, $locale, true, true, 'manual', $portalLanguage);
            $this->entityManager->persist($preferences);
        } else {
            $preferences->setLocale($locale);
            $preferences->setPortalLanguage($portalLanguage);
        }

        $this->auditLogger->log($actor, 'admin.user.preferences.updated', [
            'user_id' => $user->getId(),
            'locale' => $preferences->getLocale(),
            'portal_language' => $preferences->getPortalLanguage(),
        ]);

        $this->entityManager->flush();

        return new RedirectResponse('/admin/users?preferences_updated=' . $user->getId());
    }

    private function requireAdmin(Request $request): ?User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return null;
        }

        return $actor;
    }

    private function renderIndex(Request $request, array $errors = [], array $overrides = []): string
    {
        $users = $this->userRepository->findBy([], ['email' => 'ASC']);
        $rows = [];

        foreach ($users as $user) {
            $preferences = $this->invoicePreferencesRepository->findOneByCustomer($user);
            $rows[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'type' => $user->getType()->value,
                'created_at' => $user->getCreatedAt(),
                'locale' => $preferences?->getLocale() ?? 'de_DE',
                'portal_language' => $preferences?->getPortalLanguage() ?? 'de',
                'has_preferences' => $preferences !== null,
            ];
        }

        $updatedId = $request->query->get('updated');
        $preferencesUpdatedId = $request->query->get('preferences_updated');
        $success = [];
        if ($preferencesUpdatedId !== null) {
            $success[] = sprintf('Preferences updated for user #%s.', $preferencesUpdatedId);
        }
        $createdId = $request->query->get('created');
        if ($createdId !== null) {
            $success[] = sprintf('User #%s created.', $createdId);
        }
        if ($updatedId !== null) {
            $success[] = sprintf('User #%s updated.', $updatedId);
        }

        return $this->twig->render('admin/users/index.html.twig', [
            'activeNav' => 'users',
            'users' => $rows,
            'errors' => $errors,
            'success' => $success,
            'locales' => self::LOCALE_OPTIONS,
            'portalLanguages' => self::PORTAL_LANGUAGE_OPTIONS,
            'createForm' => [
                'email' => '',
                'type' => UserType::Customer->value,
            ],
        ] + $overrides);
    }

    private function validateUserPayload(
        string $email,
        string $password,
        string $passwordConfirm,
        string $typeValue,
        ?User $existingUser,
        bool $passwordRequired,
    ): array {
        $errors = [];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if ($email !== '') {
            $existing = $this->userRepository->findOneByEmail($email);
            if ($existing !== null && ($existingUser === null || $existing->getId() !== $existingUser->getId())) {
                $errors[] = 'An account with this email already exists.';
            }
        }

        if ($passwordRequired && $password === '') {
            $errors[] = 'Password is required.';
        }

        if ($password !== '') {
            if (mb_strlen($password) < 8) {
                $errors[] = 'Password must be at least 8 characters long.';
            }
            if ($password !== $passwordConfirm) {
                $errors[] = 'Passwords do not match.';
            }
        }

        if (UserType::tryFrom($typeValue) === null) {
            $errors[] = 'Select a valid user type.';
        }

        return $errors;
    }
}
