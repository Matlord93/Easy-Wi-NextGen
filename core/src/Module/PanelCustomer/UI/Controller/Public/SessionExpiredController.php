<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Security\PostLoginRedirectResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class SessionExpiredController
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/session-expired', name: 'public_session_expired', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $target = PostLoginRedirectResolver::localTarget($request->query->all()['target'] ?? null);

        return new Response(
            $this->twig->render('public/auth/session_expired.html.twig', ['loginUrl' => null === $target ? '/login' : '/login?target='.rawurlencode($target)]),
            Response::HTTP_OK,
            ['Cache-Control' => 'private, no-store'],
        );
    }
}
