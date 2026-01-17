<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/marketplace')]
final class AdminMarketplaceController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    #[Route(path: '', name: 'admin_marketplace', methods: ['GET'])]
    public function index(): Response
    {
        return new Response($this->twig->render('admin/marketplace/index.html.twig', [
            'activeNav' => 'marketplace',
        ]));
    }
}
