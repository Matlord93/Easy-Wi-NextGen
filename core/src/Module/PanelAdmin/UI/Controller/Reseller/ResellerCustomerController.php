<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Reseller;

use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\UserRepository;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/reseller/customers')]
final class ResellerCustomerController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'reseller_customers', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $reseller = $this->resolveReseller($request);
        if ($reseller === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->renderIndex($reseller));
    }

    #[Route(path: '', name: 'reseller_customers_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $reseller = $this->resolveReseller($request);
        if ($reseller === null) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $payload = $request->request->all();
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $errors = [];

        if ($email === '') {
            $errors[] = 'Email is required.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        }

        if ($email !== '' && $this->userRepository->findOneByEmail($email) !== null) {
            $errors[] = 'Email already exists.';
        }

        if ($errors !== []) {
            return new Response(
                $this->renderIndex($reseller, [
                    'email' => $email,
                ], $errors),
                Response::HTTP_BAD_REQUEST
            );
        }

        $customer = new User($email, UserType::Customer);
        $customer->setPasswordHash($this->passwordHasher->hashPassword($customer, $password));
        $customer->setResellerOwner($reseller);

        $this->entityManager->persist($customer);
        $preferences = new InvoicePreferences($customer, 'de_DE', true, true, 'manual', 'de');
        $this->entityManager->persist($preferences);
        $this->entityManager->flush();

        $this->auditLogger->log($reseller, 'user.created', [
            'user_id' => $customer->getId(),
            'email' => $customer->getEmail(),
            'type' => $customer->getType()->value,
            'reseller_id' => $reseller->getId(),
        ]);

        $this->entityManager->flush();

        return new RedirectResponse('/reseller/customers');
    }

    private function resolveReseller(Request $request): ?User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Reseller) {
            return null;
        }

        return $actor;
    }

    private function renderIndex(User $reseller, array $form = [], array $errors = []): string
    {
        $customers = $this->userRepository->findCustomersForReseller($reseller);

        return $this->twig->render('reseller/customers/index.html.twig', [
            'customers' => $customers,
            'form' => [
                'email' => $form['email'] ?? '',
            ],
            'errors' => $errors,
            'activeNav' => 'customers',
        ]);
    }
}
