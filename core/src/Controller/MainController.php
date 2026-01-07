<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/')]
final class MainController
{
    #[Route(path: '', name: 'main_index', methods: ['GET'])]
    public function index(): Response
    {
        return new RedirectResponse('/admin');
    }
}
