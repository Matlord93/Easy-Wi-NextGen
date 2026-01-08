<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\InvoicePreferences;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\UserRepository;
use App\Security\SessionTokenGenerator;
use App\Service\AuditLogger;
use App\Service\SiteResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PublicRegistrationController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SessionTokenGenerator $tokenGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly SiteResolver $siteResolver,
        #[Autowire(service: 'limiter.public_registration')]
        private readonly RateLimiterFactory $registrationLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/register', name: 'public_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $form = [
            'email' => '',
            'accept_terms' => false,
            'accept_privacy' => false,
        ];
        $errors = [];
        $registered = false;
        $status = Response::HTTP_OK;
        $retryAfter = null;

        if ($request->isMethod('POST')) {
            $limiter = $this->registrationLimiter->create($request->getClientIp() ?? 'public');
            $limit = $limiter->consume(1);
            if (!$limit->isAccepted()) {
                $status = Response::HTTP_TOO_MANY_REQUESTS;
                $errors[] = 'Too many registration attempts. Please try again in a moment.';
                $retryAfter = $limit->getRetryAfter();
            } else {
                $payload = $request->request->all();
                $email = trim((string) ($payload['email'] ?? ''));
                $password = (string) ($payload['password'] ?? '');
                $passwordConfirm = (string) ($payload['password_confirm'] ?? '');
                $acceptTerms = ($payload['accept_terms'] ?? '') === 'on';
                $acceptPrivacy = ($payload['accept_privacy'] ?? '') === 'on';

                $form = [
                    'email' => $email,
                    'accept_terms' => $acceptTerms,
                    'accept_privacy' => $acceptPrivacy,
                ];

                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Enter a valid email address.';
                }

                if (mb_strlen($password) < 8) {
                    $errors[] = 'Password must be at least 8 characters long.';
                }

                if ($password !== $passwordConfirm) {
                    $errors[] = 'Passwords do not match.';
                }

                if (!$acceptTerms || !$acceptPrivacy) {
                    $errors[] = 'You must accept the terms and privacy policy.';
                }

                if ($email !== '' && $this->users->findOneByEmail($email) !== null) {
                    $errors[] = 'An account with this email already exists.';
                }

                if ($errors === []) {
                    $user = new User($email, UserType::Customer);
                    $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

                    $now = new \DateTimeImmutable();
                    $ipAddress = $request->getClientIp() ?? 'unknown';
                    $token = $this->tokenGenerator->generateToken();

                    $user->setEmailVerificationTokenHash($this->tokenGenerator->hashToken($token));
                    $user->setEmailVerificationExpiresAt($now->modify('+2 days'));
                    $user->recordConsents($ipAddress, $now);

                    $this->entityManager->persist($user);
                    $preferences = new InvoicePreferences($user, 'de_DE', true, true, 'manual', 'de');
                    $this->entityManager->persist($preferences);
                    $this->entityManager->flush();

                    $this->auditLogger->log($user, 'customer.registered', [
                        'user_id' => $user->getId(),
                        'email' => $user->getEmail(),
                        'ip_address' => $ipAddress,
                        'site_id' => $site->getId(),
                        'site_host' => $site->getHost(),
                        'terms_accepted_at' => $user->getTermsAcceptedAt()?->format(DATE_ATOM),
                        'privacy_accepted_at' => $user->getPrivacyAcceptedAt()?->format(DATE_ATOM),
                    ]);

                    $this->entityManager->flush();

                    $registered = true;
                } else {
                    $status = Response::HTTP_BAD_REQUEST;
                }
            }
        }

        $response = new Response($this->twig->render('public/auth/register.html.twig', [
            'form' => $form,
            'errors' => $errors,
            'registered' => $registered,
            'siteName' => $site->getName(),
        ]), $status);

        if ($retryAfter !== null) {
            $seconds = max(1, $retryAfter->getTimestamp() - time());
            $response->headers->set('Retry-After', (string) $seconds);
        }

        return $response;
    }
}
