<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\ModuleRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/modules')]
final class AdminModuleController
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_modules', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof \App\Entity\User || $actor->getType() !== \App\Enum\UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/modules/index.html.twig', [
            'modules' => $this->moduleRegistry->listModules(),
            'activeNav' => 'modules',
        ]));
    }

    #[Route(path: '/{key}/toggle', name: 'admin_modules_toggle', methods: ['POST'])]
    public function toggle(Request $request, string $key): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof \App\Entity\User || $actor->getType() !== \App\Enum\UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $enabled = filter_var($request->request->get('enabled', 'false'), FILTER_VALIDATE_BOOL);
        $this->moduleRegistry->setEnabled($key, $enabled, $actor);
        $this->entityManager->flush();

        return new RedirectResponse('/admin/modules');
    }
}
