<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Gameserver\Infrastructure\Repository\GameProfileRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/game-profiles')]
final class AdminGameProfileController
{
    public function __construct(
        private readonly GameProfileRepository $gameProfileRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_game_profiles', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $profiles = $this->gameProfileRepository->findBy([], ['gameKey' => 'ASC']);

        return new Response($this->twig->render('admin/game-profiles/index.html.twig', [
            'profiles' => $profiles,
            'activeNav' => 'game-profiles',
        ]));
    }
}
