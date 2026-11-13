<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Module\Core\Domain\Entity\User;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Application\MinecraftCatalogSynchronizer;
use App\Repository\TemplateRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route('/admin/minecraft')]
final readonly class AdminMinecraftController
{
    public function __construct(private TemplateRepository $templates, private MinecraftCatalogService $catalog, private MinecraftCatalogSynchronizer $synchronizer, private Environment $twig)
    {
    }

    #[Route('', name: 'admin_minecraft', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden', 403);
        }
        $catalog = $this->catalog->getUiCatalog();
        $templates = $this->templates->createQueryBuilder('t')->where("t.gameKey LIKE 'minecraft_%'")->orderBy('t.gameKey', 'ASC')->getQuery()->getResult();
        $byChannel = [];
        $keys = ['minecraft_vanilla_all' => 'vanilla', 'minecraft_paper_all' => 'paper', 'minecraft_spigot_all' => 'spigot', 'minecraft_bedrock' => 'bedrock', 'minecraft_bungeecord_all' => 'bungeecord'];
        foreach ($templates as $template) {
            if (isset($keys[$template->getGameKey()])) {
                $byChannel[$keys[$template->getGameKey()]] = $template->getId();
            }
        }

        return new Response($this->twig->render('admin/minecraft/index.html.twig', [
            'catalog' => $catalog, 'syncStatus' => $this->synchronizer->publicStatus(),
            'channels' => MinecraftVersionCatalog::CHANNELS, 'templateIds' => $byChannel,
            'activeNav' => 'minecraft',
        ]));
    }

    #[Route('/import', name: 'admin_minecraft_import', methods: ['POST'])]
    public function legacyImport(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden', 403);
        }

        return new RedirectResponse('/admin/minecraft', Response::HTTP_SEE_OTHER);
    }
}
