<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\MinecraftVersionImportService;
use App\Module\Core\Domain\Entity\User;
use App\Repository\MinecraftVersionCatalogRepository;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/admin/minecraft')]
final class AdminMinecraftController
{
    public function __construct(
        private readonly TemplateRepository $templateRepository,
        private readonly MinecraftVersionCatalogRepository $repository,
        private readonly MinecraftVersionImportService $importService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'admin_minecraft', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $templates = $this->templateRepository->createQueryBuilder('t')
            ->where("t.gameKey LIKE 'minecraft_%'")
            ->orderBy('t.gameKey', 'ASC')
            ->getQuery()
            ->getResult();

        $channelStats = [];
        foreach (MinecraftVersionImportService::CHANNELS as $channel) {
            $total = $this->repository->count(['channel' => $channel]);
            $active = $this->repository->count(['channel' => $channel, 'isActive' => true]);
            $versions = $this->repository->findVersionsByChannel($channel, true);
            $latest = count($versions) > 0 ? $versions[0] : null;
            $channelStats[$channel] = [
                'total' => $total,
                'active' => $active,
                'latest' => $latest,
            ];
        }

        return new Response($this->twig->render('admin/minecraft/index.html.twig', [
            'templates' => $templates,
            'channelStats' => $channelStats,
            'importResult' => $this->importResultFromQuery($request),
            'activeNav' => 'minecraft',
        ]));
    }

    #[Route(path: '/import', name: 'admin_minecraft_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $channel = trim((string) $request->request->get('channel', 'all'));
        $channels = $channel === 'all' ? MinecraftVersionImportService::CHANNELS : [$channel];
        if ($channel !== 'all' && !in_array($channel, MinecraftVersionImportService::CHANNELS, true)) {
            $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'deactivated' => 0, 'errors' => [$this->trans('admin_minecraft_import_invalid_channel')], 'dryRun' => false];
        } else {
            $summary = $this->importService->import($channels, false, (bool) $request->request->get('force'), (bool) $request->request->get('deactivate_missing'));
        }

        return new RedirectResponse('/admin/minecraft?' . $this->buildImportResultQuery($summary));
    }

    /**
     * @param array{created:int,updated:int,skipped:int,deactivated:int,errors:array<int,string>,dryRun:bool} $summary
     */
    private function buildImportResultQuery(array $summary): string
    {
        return http_build_query([
            'import_created' => $summary['created'],
            'import_updated' => $summary['updated'],
            'import_skipped' => $summary['skipped'],
            'import_deactivated' => $summary['deactivated'],
            'import_errors' => count($summary['errors']),
            'import_error_messages' => $summary['errors'] !== [] ? implode("\n", $summary['errors']) : null,
        ]);
    }

    /** @return array{created:int,updated:int,skipped:int,deactivated:int,errors:int,errorMessages:array<int,string>}|null */
    private function importResultFromQuery(Request $request): ?array
    {
        if (!$request->query->has('import_created')) {
            return null;
        }

        $messages = trim((string) $request->query->get('import_error_messages', ''));

        return [
            'created' => max(0, (int) $request->query->get('import_created', 0)),
            'updated' => max(0, (int) $request->query->get('import_updated', 0)),
            'skipped' => max(0, (int) $request->query->get('import_skipped', 0)),
            'deactivated' => max(0, (int) $request->query->get('import_deactivated', 0)),
            'errors' => max(0, (int) $request->query->get('import_errors', 0)),
            'errorMessages' => $messages !== '' ? preg_split('/\R/', $messages) ?: [] : [],
        ];
    }

    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'portal');
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }
}
