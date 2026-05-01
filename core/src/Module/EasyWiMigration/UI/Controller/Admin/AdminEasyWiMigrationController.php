<?php

declare(strict_types=1);

namespace App\Module\EasyWiMigration\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\EasyWiMigration\Application\EasyWiConnectionConfig;
use App\Module\EasyWiMigration\Application\EasyWiMigrationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/easywi-migration')]
final class AdminEasyWiMigrationController
{
    private const ALL_ENTITIES = ['users', 'gameservers', 'voice', 'webspaces', 'domains', 'mailboxes', 'invoices'];

    public function __construct(
        private readonly EasyWiMigrationService $migrationService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_easywi_migration', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/easywi_migration/index.html.twig', [
            'activeNav' => 'migration',
            'entities' => self::ALL_ENTITIES,
        ]));
    }

    #[Route(path: '/probe', name: 'admin_easywi_migration_probe', methods: ['POST'])]
    public function probe(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $config = $this->buildConfig($request);
        if ($config === null) {
            return new JsonResponse(['error' => 'Missing required fields (host, dbname, user).'], 400);
        }

        try {
            $counts = $this->migrationService->probe($config);
            return new JsonResponse(['counts' => $counts]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route(path: '/run', name: 'admin_easywi_migration_run', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $config = $this->buildConfig($request);
        if ($config === null) {
            return new JsonResponse(['error' => 'Missing required fields (host, dbname, user).'], 400);
        }

        $payload = $request->toArray();
        $rawEntities = (array) ($payload['entities'] ?? self::ALL_ENTITIES);
        $entities = array_values(array_intersect($rawEntities, self::ALL_ENTITIES));
        $dryRun = (bool) ($payload['dry_run'] ?? true);

        try {
            $results = $this->migrationService->run($config, $entities, $dryRun);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        $summary = [];
        foreach ($results as $result) {
            $summary[] = [
                'entity' => $result->entity,
                'imported' => $result->imported,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
                'total' => $result->total(),
                'errors' => array_slice($result->errors, 0, 20),
            ];
        }

        return new JsonResponse([
            'dry_run' => $dryRun,
            'results' => $summary,
        ]);
    }

    private function buildConfig(Request $request): ?EasyWiConnectionConfig
    {
        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            $payload = $request->request->all();
        }

        $host = trim((string) ($payload['host'] ?? ''));
        $dbname = trim((string) ($payload['dbname'] ?? ''));
        $user = trim((string) ($payload['user'] ?? ''));

        if ($host === '' || $dbname === '' || $user === '') {
            return null;
        }

        return new EasyWiConnectionConfig(
            host: $host,
            port: (int) ($payload['port'] ?? 3306),
            dbName: $dbname,
            username: $user,
            password: (string) ($payload['password'] ?? ''),
            tablePrefix: trim((string) ($payload['prefix'] ?? 'easywi_')) ?: 'easywi_',
        );
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->isAdmin();
    }
}
