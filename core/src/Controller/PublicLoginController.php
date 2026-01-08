<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\UserSession;
use App\Enum\UserType;
use App\Repository\UserRepository;
use App\Security\SessionTokenGenerator;
use App\Service\AuditLogger;
use App\Service\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PublicLoginController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SessionTokenGenerator $tokenGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly SiteResolver $siteResolver,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/login', name: 'public_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $form = [
            'email' => '',
        ];
        $errors = [];
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $payload = $request->request->all();
            $email = trim((string) ($payload['email'] ?? ''));
            $password = (string) ($payload['password'] ?? '');

            $form['email'] = $email;

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address.';
            }

            if ($password === '') {
                $errors[] = 'Enter your password.';
            }

            if ($errors === []) {
                $user = $this->users->findOneByEmail($email);
                if ($user === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
                    $errors[] = 'Invalid credentials.';
                    $status = Response::HTTP_UNAUTHORIZED;
                } else {
                    $token = $this->tokenGenerator->generateToken();
                    $session = new UserSession($user, $this->tokenGenerator->hashToken($token));
                    $session->setExpiresAt((new \DateTimeImmutable())->modify('+30 days'));

                    $this->entityManager->persist($session);
                    $this->auditLogger->log($user, 'session.created', [
                        'user_id' => $user->getId(),
                        'email' => $user->getEmail(),
                    ]);
                    $this->entityManager->flush();

                    $redirectPath = match ($user->getType()) {
                        UserType::Admin => '/admin',
                        UserType::Reseller => '/reseller/customers',
                        default => '/dashboard',
                    };
                    $response = new RedirectResponse($redirectPath);
                    $response->headers->setCookie(
                        Cookie::create('easywi_session', $token)
                            ->withPath('/')
                            ->withSecure($request->isSecure())
                            ->withHttpOnly(true)
                            ->withSameSite('lax')
                            ->withExpires((new \DateTimeImmutable())->modify('+30 days'))
                    );

                    return $response;
                }
            } else {
                $status = Response::HTTP_BAD_REQUEST;
            }
        }

        return new Response($this->twig->render('public/auth/login.html.twig', [
            'form' => $form,
            'errors' => $errors,
            'siteName' => $site->getName(),
        ]), $status);
    }
}
