<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/mail')]
final class AdminMailHealthPageController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    #[Route(path: '/health', name: 'admin_mail_health', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/mail-system/health.html.twig', [
            'activeNav' => 'mail-health',
            'healthEndpoint' => '/api/v1/admin/mail/nodes/health',
            'repairEndpointBase' => '/api/v1/admin/mail/nodes',
        ]));
    }

    #[Route(path: '/matrix', name: 'admin_mail_matrix', methods: ['GET'])]
    public function matrix(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/mail-system/matrix.html.twig', [
            'activeNav' => 'mail-matrix',
            'healthEndpoint' => '/api/v1/admin/mail/nodes/health',
            'repairEndpointBase' => '/api/v1/admin/mail/nodes',
            'metricsEndpoint' => '/api/v1/admin/mail/nodes/metrics',
        ]));
    }
}
