<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Attribute\RequiresModule;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[RequiresModule('game')]
final readonly class CustomerGameserverFilesController
{
    public function __construct(private CustomerInstanceController $instances)
    {
    }

    #[Route('/instances/{id}/files', name: 'customer_instance_files', methods: ['GET'])]
    public function __invoke(Request $request, int $id): Response
    {
        return $this->instances->filesPage($request, $id);
    }
}
