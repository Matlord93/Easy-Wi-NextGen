<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\MinecraftVersionImportService;
use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Module\Core\Domain\Entity\User;
use App\Repository\MinecraftVersionCatalogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[Route(path: '/admin/minecraft-versions')]
final class AdminMinecraftVersionController
{
    public function __construct(
        private readonly MinecraftVersionCatalogRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MinecraftVersionImportService $importService,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'admin_minecraft_versions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) { return new Response($this->trans('error_forbidden'), Response::HTTP_FORBIDDEN); }
        $qb = $this->repository->createQueryBuilder('v')->orderBy('v.channel', 'ASC')->addOrderBy('v.mcVersion', 'DESC')->addOrderBy('v.build', 'DESC');
        foreach (['channel', 'source'] as $filter) {
            $value = trim((string) $request->query->get($filter, ''));
            if ($value !== '') { $qb->andWhere(sprintf('v.%s = :%s', $filter, $filter))->setParameter($filter, $value); }
        }
        $active = trim((string) $request->query->get('active', ''));
        if ($active !== '') { $qb->andWhere('v.isActive = :active')->setParameter('active', $active === '1'); }
        $preview = trim((string) $request->query->get('preview', ''));
        if ($preview === '1') { $qb->andWhere('LOWER(v.downloadUrl) LIKE :preview')->setParameter('preview', '%preview%'); }
        if ($preview === '0') { $qb->andWhere('LOWER(v.downloadUrl) NOT LIKE :preview')->setParameter('preview', '%preview%'); }
        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') { $qb->andWhere('v.mcVersion LIKE :q OR v.build LIKE :q OR v.downloadUrl LIKE :q OR v.notes LIKE :q')->setParameter('q', '%'.$q.'%'); }

        return new Response($this->twig->render('admin/minecraft_versions/index.html.twig', [
            'versions' => $qb->getQuery()->getResult(),
            'filters' => ['channel' => $request->query->get('channel', ''), 'source' => $request->query->get('source', ''), 'active' => $active, 'preview' => $preview, 'q' => $q],
            'importResult' => $this->importResultFromQuery($request),
            'activeNav' => 'minecraft_versions',
        ]));
    }

    #[Route(path: '/new', name: 'admin_minecraft_versions_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$this->isAdmin($request)) { return new Response($this->trans('error_forbidden'), Response::HTTP_FORBIDDEN); }
        $version = new MinecraftVersionCatalog('vanilla', '', null, 'https://example.com/server.jar');
        $version->setSource('manual');
        if ($request->isMethod('POST')) {
            $errors = $this->applyForm($version, $request, true);
            if ($errors === []) {
                $this->entityManager->persist($version);
                $this->entityManager->flush();
                return new RedirectResponse('/admin/minecraft-versions');
            }
        }
        return new Response($this->twig->render('admin/minecraft_versions/form.html.twig', ['version' => $version, 'errors' => $errors ?? [], 'activeNav' => 'minecraft_versions']));
    }

    #[Route(path: '/{id<\d+>}/edit', name: 'admin_minecraft_versions_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) { return new Response($this->trans('error_forbidden'), Response::HTTP_FORBIDDEN); }
        $version = $this->repository->find($id);
        if (!$version instanceof MinecraftVersionCatalog) { return new Response($this->trans('error_not_found'), Response::HTTP_NOT_FOUND); }
        if ($request->isMethod('POST')) {
            $errors = $this->applyForm($version, $request, false);
            if ($errors === []) {
                $this->entityManager->persist($version);
                $this->entityManager->flush();
                return new RedirectResponse('/admin/minecraft-versions');
            }
        }
        return new Response($this->twig->render('admin/minecraft_versions/form.html.twig', ['version' => $version, 'errors' => $errors ?? [], 'activeNav' => 'minecraft_versions']));
    }

    #[Route(path: '/{id<\d+>}/toggle', name: 'admin_minecraft_versions_toggle', methods: ['POST'])]
    public function toggle(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) { return new Response($this->trans('error_forbidden'), Response::HTTP_FORBIDDEN); }
        $version = $this->repository->find($id);
        if ($version instanceof MinecraftVersionCatalog) { $version->setIsActive(!$version->isActive()); $this->entityManager->flush(); }
        return new RedirectResponse('/admin/minecraft-versions');
    }

    #[Route(path: '/{id<\d+>}/delete', name: 'admin_minecraft_versions_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) { return new Response($this->trans('error_forbidden'), Response::HTTP_FORBIDDEN); }
        $version = $this->repository->find($id);
        if (!$version instanceof MinecraftVersionCatalog) { return new RedirectResponse('/admin/minecraft-versions'); }
        $installed = $this->countInstalled($version);
        if ($installed > 0) {
            return new Response($this->trans('admin_minecraft_versions_delete_blocked', ['%count%' => (string) $installed]), Response::HTTP_CONFLICT);
        }
        $this->entityManager->remove($version);
        $this->entityManager->flush();
        return new RedirectResponse('/admin/minecraft-versions');
    }

    #[Route(path: '/import', name: 'admin_minecraft_versions_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isAdmin($request)) { return new Response($this->trans('error_forbidden'), Response::HTTP_FORBIDDEN); }
        $channel = trim((string) $request->request->get('channel', 'all'));
        $channels = $channel === 'all' ? MinecraftVersionImportService::CHANNELS : [$channel];
        if ($channel !== 'all' && !in_array($channel, MinecraftVersionImportService::CHANNELS, true)) {
            $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'deactivated' => 0, 'errors' => [$this->trans('admin_minecraft_versions_import_invalid_channel')], 'dryRun' => false];
        } else {
            $summary = $this->importService->import($channels, false, (bool) $request->request->get('force'), (bool) $request->request->get('deactivate_missing'));
        }
        return new RedirectResponse('/admin/minecraft-versions?'.$this->buildImportResultQuery($summary));
    }

    private function applyForm(MinecraftVersionCatalog $version, Request $request, bool $create): array
    {
        $channel = trim((string) $request->request->get('channel', ''));
        $mcVersion = trim((string) $request->request->get('mc_version', ''));
        $build = trim((string) $request->request->get('build', ''));
        $downloadUrl = trim((string) $request->request->get('download_url', ''));
        $javaVersion = trim((string) $request->request->get('java_version', ''));
        $source = trim((string) $request->request->get('source', 'manual')) ?: 'manual';
        $errors = [];
        if (!in_array($channel, MinecraftVersionCatalog::CHANNELS, true)) { $errors[] = $this->trans('admin_minecraft_versions_error_channel'); }
        if ($mcVersion === '') { $errors[] = $this->trans('admin_minecraft_versions_error_mc_version_required'); }
        if ($downloadUrl === '' || !filter_var($downloadUrl, FILTER_VALIDATE_URL)) { $errors[] = $this->trans('admin_minecraft_versions_error_download_url'); }
        if ($channel === 'paper' && $build === '') { $errors[] = $this->trans('admin_minecraft_versions_error_paper_build'); }
        if ($channel !== 'paper' && $build !== '') { $errors[] = $this->trans('admin_minecraft_versions_error_build_not_allowed'); }
        if ($channel === 'bedrock' && $javaVersion !== '') { $errors[] = $this->trans('admin_minecraft_versions_error_bedrock_java'); }
        if ($channel === 'bedrock' && !$this->isZipUrl($downloadUrl)) { $errors[] = $this->trans('admin_minecraft_versions_error_bedrock_zip'); }
        if ($channel === 'bedrock' && !$this->isUnzipAvailable()) { $errors[] = $this->trans('admin_minecraft_versions_error_unzip_missing'); }
        if ($javaVersion !== '' && !in_array($javaVersion, MinecraftVersionCatalog::JAVA_VERSIONS, true)) { $errors[] = $this->trans('admin_minecraft_versions_error_java_version'); }
        if ($channel === 'bedrock') { $javaVersion = ''; }
        if ($channel !== 'paper') { $build = ''; }
        if (!in_array($source, MinecraftVersionCatalog::SOURCES, true)) { $source = 'manual'; }
        $existing = $this->repository->findOneBy(['channel' => $channel, 'mcVersion' => $mcVersion, 'build' => $build !== '' ? $build : null]);
        if ($existing instanceof MinecraftVersionCatalog && ($create || $existing->getId() !== $version->getId())) { $errors[] = $this->trans('admin_minecraft_versions_error_unique'); }
        if ($errors !== []) { return $errors; }
        $version->setChannel($channel);
        $version->setMcVersion($mcVersion);
        $version->setBuild($build !== '' ? $build : null);
        $version->setDownloadUrl($downloadUrl);
        $version->setJavaVersion($javaVersion !== '' ? $javaVersion : null);
        $version->setSource($source);
        $version->setIsActive((bool) $request->request->get('is_active'));
        $version->setNotes(trim((string) $request->request->get('notes', '')) ?: null);
        return [];
    }

    private function countInstalled(MinecraftVersionCatalog $version): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(\App\Module\Core\Domain\Entity\Instance::class, 'i')
            ->where('i.installedChannel = :channel')
            ->andWhere('i.installedVersion = :version')
            ->setParameter('channel', $version->getChannel())
            ->setParameter('version', $version->getMcVersion());
        if ($version->getBuild() === null) { $qb->andWhere('i.installedBuildId IS NULL'); } else { $qb->andWhere('i.installedBuildId = :build')->setParameter('build', $version->getBuild()); }
        return (int) $qb->getQuery()->getSingleScalarResult();
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

    private function isZipUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        return str_ends_with($path, '.zip');
    }

    private function isUnzipAvailable(): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'unzip';
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
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
