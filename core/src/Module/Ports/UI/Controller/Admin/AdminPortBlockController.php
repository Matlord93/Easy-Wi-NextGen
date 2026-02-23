<?php

declare(strict_types=1);

namespace App\Module\Ports\UI\Controller\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/admin/port-blocks')]
final class AdminPortBlockController
{
    #[Route(path: '', name: 'admin_port_blocks', methods: ['GET'])]
    #[Route(path: '', name: 'admin_port_blocks_create_page', methods: ['POST'])]
    public function redirectToServerPorts(Request $request): RedirectResponse
    {
        $query = $request->query->all();
        $target = '/admin/port-pools';
        if ($query !== []) {
            $target .= '?' . http_build_query($query);
        }

        return new RedirectResponse($target);
    }
}
