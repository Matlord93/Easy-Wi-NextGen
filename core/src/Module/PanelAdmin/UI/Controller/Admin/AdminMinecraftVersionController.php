<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Module\Core\Domain\Entity\User;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Application\MinecraftCatalogSynchronizer;
use App\Repository\MinecraftVersionCatalogRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route('/admin/minecraft-versions')]
final readonly class AdminMinecraftVersionController
{
    public function __construct(private MinecraftVersionCatalogRepository $repository, private MinecraftCatalogService $catalog, private MinecraftCatalogSynchronizer $catalogSynchronizer, private CsrfTokenManagerInterface $csrfTokens, private Environment $twig)
    {
    }

    #[Route('', name: 'admin_minecraft_versions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden', 403);
        }
        $this->catalog->getUiCatalog();
        $channel = $request->query->getString('channel', '');
        $search = trim($request->query->getString('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $qb = $this->repository->createQueryBuilder('v')->where('v.isActive = true');
        if (in_array($channel, MinecraftVersionCatalog::CHANNELS, true)) {
            $qb->andWhere('v.channel = :channel')->setParameter('channel', $channel);
        }
        if ('' !== $search) {
            $qb->andWhere('v.mcVersion LIKE :q OR v.build LIKE :q')->setParameter('q', '%'.$search.'%');
        }
        $count = (int) (clone $qb)->select('COUNT(v.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($count / 100));
        $page = min($page, $pages);
        $versions = $qb->orderBy('v.channel', 'ASC')->addOrderBy('v.releasedAt', 'DESC')->addOrderBy('v.mcVersion', 'DESC')->addOrderBy('v.build', 'DESC')->setMaxResults(100)->setFirstResult(($page - 1) * 100)->getQuery()->getResult();

        return new Response($this->twig->render('admin/minecraft_versions/index.html.twig', [
            'versions' => $versions, 'syncStatus' => $this->catalogSynchronizer->publicStatus(),
            'channels' => MinecraftVersionCatalog::CHANNELS, 'filters' => ['channel' => $channel, 'q' => $search],
            'page' => $page, 'pages' => $pages, 'count' => $count, 'activeNav' => 'minecraft_versions',
        ]));
    }

    #[Route('/sync', name: 'admin_minecraft_versions_sync', methods: ['POST'])]
    public function synchronize(Request $request): Response
    {
        if (!$this->isAdmin($request) || !$this->csrfTokens->isTokenValid(new CsrfToken('minecraft-catalog-sync', (string) $request->request->get('_token')))) {
            return new Response('Forbidden', 403);
        }
        $this->catalogSynchronizer->requestRefresh();
        $this->catalogSynchronizer->startIfDue();

        return new RedirectResponse('/admin/minecraft-versions', Response::HTTP_SEE_OTHER);
    }

    // Keep bookmarks/form targets harmless while old release pages are still open.
    #[Route('/new', name: 'admin_minecraft_versions_new', methods: ['GET', 'POST'])]
    #[Route('/{id<\d+>}/edit', name: 'admin_minecraft_versions_edit', methods: ['GET', 'POST'])]
    #[Route('/{id<\d+>}/toggle', name: 'admin_minecraft_versions_toggle', methods: ['POST'])]
    #[Route('/{id<\d+>}/delete', name: 'admin_minecraft_versions_delete', methods: ['POST'])]
    #[Route('/upload-data-file', name: 'admin_minecraft_versions_upload_data_file', methods: ['POST'])]
    #[Route('/import', name: 'admin_minecraft_versions_import', methods: ['POST'])]
    public function legacyManualAction(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden', 403);
        }

        return new RedirectResponse('/admin/minecraft-versions', Response::HTTP_SEE_OTHER);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = RequestActor::user($request);

        return $actor instanceof User && $actor->isAdmin();
    }
}
