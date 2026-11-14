<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Domain\Entity\User;
use App\Module\PanelAdmin\Application\AdminWorkspaceGuide;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AdminGettingStartedController extends AbstractController
{
    #[Route('/admin/getting-started', name: 'admin_getting_started', methods: ['GET'])]
    public function index(Request $request, AdminWorkspaceGuide $guide): Response
    {
        $actor = RequestActor::user($request);
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException();
        }

        return $this->render('admin/workspace/index.html.twig', [
            'guides' => $guide->all(),
            'activeNav' => 'getting-started',
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }
}
