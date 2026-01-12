<?php

declare(strict_types=1);

namespace App\Controller\Customer;

use App\Entity\Database;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\DatabaseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/databases')]
final class CustomerDatabaseController
{
    public function __construct(
        private readonly DatabaseRepository $databaseRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_databases', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $databases = $this->databaseRepository->findByCustomer($customer);

        return new Response($this->twig->render('customer/databases/index.html.twig', [
            'activeNav' => 'databases',
            'databases' => $this->normalizeDatabases($databases),
        ]));
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    /**
     * @param Database[] $databases
     */
    private function normalizeDatabases(array $databases): array
    {
        return array_map(static function (Database $database): array {
            return [
                'id' => $database->getId(),
                'name' => $database->getName(),
                'engine' => $database->getEngine(),
                'host' => $database->getHost(),
                'port' => $database->getPort(),
                'username' => $database->getUsername(),
                'updated_at' => $database->getUpdatedAt(),
            ];
        }, $databases);
    }
}
