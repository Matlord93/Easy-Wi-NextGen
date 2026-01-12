<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Service\SiteResolver;
use App\Service\Installer\InstallerService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/')]
final class MainController
{
    public function __construct(
        private readonly SiteResolver $siteResolver,
        private readonly InstallerService $installerService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'main_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->installerService->isLocked()) {
            return new RedirectResponse('/install');
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('public/index.html.twig', [
            'siteName' => $site->getName(),
        ]));
    }
}
