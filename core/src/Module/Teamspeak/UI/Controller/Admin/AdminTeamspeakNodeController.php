<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Repository\Ts3NodeRepository;
use App\Repository\Ts6NodeRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/teamspeak/nodes')]
final class AdminTeamspeakNodeController
{
    public function __construct(
        private readonly Ts3NodeRepository $ts3NodeRepository,
        private readonly Ts6NodeRepository $ts6NodeRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_ts_nodes_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request);

        return new Response($this->twig->render('admin/ts/nodes/index.html.twig', [
            'activeNav' => 'ts-nodes',
            'ts3_nodes' => $this->ts3NodeRepository->findBy([], ['updatedAt' => 'DESC']),
            'ts6_nodes' => $this->ts6NodeRepository->findBy([], ['updatedAt' => 'DESC']),
        ]));
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }
}
