<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\GamePluginSeedCatalog;
use App\Module\Core\Application\GamePluginSeeder;
use App\Module\Core\Application\GameTemplateSeeder;
use App\Module\Core\Domain\Entity\GamePlugin;
use App\Module\Core\Domain\Entity\User;
use App\Repository\GamePluginRepository;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/admin/plugins')]
final class AdminPluginCatalogController
{
    public function __construct(
        private readonly GamePluginRepository $pluginRepository,
        private readonly TemplateRepository $templateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly GameTemplateSeeder $templateSeeder,
        private readonly GamePluginSeeder $pluginSeeder,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'admin_plugins', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $plugins = $this->pluginRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/plugins/index.html.twig', [
            'plugins' => $this->normalizePlugins($plugins),
            'summary' => $this->buildSummary($plugins),
            'empty_message' => $this->buildEmptyMessage(),
            'activeNav' => 'plugins',
        ]));
    }

    #[Route(path: '/new', name: 'admin_plugins_new', methods: ['GET'])]
    public function createPage(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $templates = $this->templateRepository->findBy([], ['displayName' => 'ASC']);

        return new Response($this->twig->render('admin/plugins/create.html.twig', [
            'form' => $this->buildFormContext(),
            'templates' => $this->normalizeTemplates($templates),
            'activeNav' => 'plugins',
        ]));
    }

    #[Route(path: '/import', name: 'admin_plugins_import_page', methods: ['GET'])]
    public function importPage(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/plugins/import.html.twig', [
            'import' => $this->buildImportContext(),
            'activeNav' => 'plugins',
        ]));
    }

    #[Route(path: '/table', name: 'admin_plugins_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $plugins = $this->pluginRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/plugins/_table.html.twig', [
            'plugins' => $this->normalizePlugins($plugins),
            'empty_message' => $this->buildEmptyMessage(),
        ]));
    }

    #[Route(path: '/form', name: 'admin_plugins_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $templates = $this->templateRepository->findBy([], ['displayName' => 'ASC']);

        return new Response($this->twig->render('admin/plugins/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'templates' => $this->normalizeTemplates($templates),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_plugins_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function editPage(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $plugin = $this->pluginRepository->find($id);
        if ($plugin === null) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $templates = $this->templateRepository->findBy([], ['displayName' => 'ASC']);

        return new Response($this->twig->render('admin/plugins/edit.html.twig', [
            'form' => $this->buildFormContext($plugin),
            'templates' => $this->normalizeTemplates($templates),
            'activeNav' => 'plugins',
        ]));
    }

    #[Route(path: '/{id}/preview', name: 'admin_plugins_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function previewPage(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $plugin = $this->pluginRepository->find($id);
        if ($plugin === null) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/plugins/preview.html.twig', [
            'plugin' => $this->normalizePlugin($plugin),
            'activeNav' => 'plugins',
        ]));
    }

    #[Route(path: '', name: 'admin_plugins_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $template = $this->resolveTemplateForGameKey($formData['game_key']);
        if ($template === null) {
            $formData['errors'][] = 'No template found for selected game key.';
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $duplicate = $this->pluginRepository->findDuplicateForGameKey($formData['game_key'], $formData['name'], $formData['version']);
        if ($duplicate !== null) {
            $formData['errors'][] = 'Plugin with same name/version already exists for this game key.';
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $plugin = new GamePlugin(
            template: $template,
            name: $formData['name'],
            version: $formData['version'],
            checksum: $formData['checksum'],
            downloadUrl: $formData['download_url'],
            description: $formData['description'],
        );

        $this->entityManager->persist($plugin);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'plugin.created', [
            'plugin_id' => $plugin->getId(),
            'template_id' => $template->getId(),
            'name' => $plugin->getName(),
            'version' => $plugin->getVersion(),
            'checksum' => $plugin->getChecksum(),
            'download_url' => $plugin->getDownloadUrl(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/plugins/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'templates' => $this->normalizeTemplates($this->templateRepository->findBy([], ['displayName' => 'ASC'])),
        ]));
        $response->headers->set('HX-Trigger', 'plugins-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_plugins_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $pluginId = filter_var($id, FILTER_VALIDATE_INT);
        if ($pluginId === false) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $plugin = $this->pluginRepository->find($pluginId);
        if ($plugin === null) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $plugin);
        }

        $template = $this->resolveTemplateForGameKey($formData['game_key']);
        if ($template === null) {
            $formData['errors'][] = 'No template found for selected game key.';
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $plugin);
        }

        $duplicate = $this->pluginRepository->findDuplicateForGameKey($formData['game_key'], $formData['name'], $formData['version'], $plugin->getId());
        if ($duplicate !== null) {
            $formData['errors'][] = 'Plugin with same name/version already exists for this game key.';
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST, $plugin);
        }

        $previous = [
            'template_id' => $plugin->getTemplate()->getId(),
            'game_key' => $plugin->getTemplate()->getGameKey(),
            'name' => $plugin->getName(),
            'version' => $plugin->getVersion(),
            'checksum' => $plugin->getChecksum(),
            'download_url' => $plugin->getDownloadUrl(),
        ];

        $plugin->setTemplate($template);
        $plugin->setName($formData['name']);
        $plugin->setVersion($formData['version']);
        $plugin->setChecksum($formData['checksum']);
        $plugin->setDownloadUrl($formData['download_url']);
        $plugin->setDescription($formData['description']);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'plugin.updated', [
            'plugin_id' => $plugin->getId(),
            'previous' => $previous,
            'current' => [
                'template_id' => $template->getId(),
                'name' => $plugin->getName(),
                'version' => $plugin->getVersion(),
                'checksum' => $plugin->getChecksum(),
                'download_url' => $plugin->getDownloadUrl(),
            ],
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/plugins/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'templates' => $this->normalizeTemplates($this->templateRepository->findBy([], ['displayName' => 'ASC'])),
        ]));
        $response->headers->set('HX-Trigger', 'plugins-changed');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_plugins_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $pluginId = filter_var($id, FILTER_VALIDATE_INT);
        if ($pluginId === false) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $plugin = $this->pluginRepository->find($pluginId);
        if ($plugin === null) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $this->auditLogger->log($actor, 'plugin.deleted', [
            'plugin_id' => $plugin->getId(),
            'template_id' => $plugin->getTemplate()->getId(),
            'name' => $plugin->getName(),
            'version' => $plugin->getVersion(),
        ]);

        $this->entityManager->remove($plugin);
        $this->entityManager->flush();

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'plugins-changed');

        return $response;
    }


    #[Route(path: '/seed', name: 'admin_plugins_seed', methods: ['POST'])]
    public function seedCatalog(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $templatesCreated = $this->templateSeeder->seedTemplatesOnly($this->entityManager);
        $pluginResult = $this->pluginSeeder->seed($this->entityManager, true);

        $this->auditLogger->log($actor, 'plugin.seeded', [
            'imported' => $pluginResult['plugins'],
            'updated' => $pluginResult['updated'],
            'entries' => $pluginResult['entries'],
            'templates_created' => $templatesCreated,
            'skipped_missing_template' => $pluginResult['skipped_missing_template'],
            'missing_game_keys' => $pluginResult['missing_game_keys'],
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/plugins/_seed_result.html.twig', [
            'result' => [
                'templates_created' => $templatesCreated,
                'imported' => $pluginResult['plugins'],
                'updated' => $pluginResult['updated'],
                'skipped_missing_template' => $pluginResult['skipped_missing_template'],
                'missing_game_keys' => $pluginResult['missing_game_keys'],
            ],
        ]));
        $response->headers->set('HX-Trigger', 'plugins-changed');

        return $response;
    }

    #[Route(path: '/import', name: 'admin_plugins_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        $payload = trim((string) $request->request->get('payload', ''));
        if ($payload === '') {
            return $this->renderImportWithErrors($payload, ['Import payload is required.'], Response::HTTP_BAD_REQUEST);
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return $this->renderImportWithErrors($payload, ['Import payload must be valid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        $items = array_values($decoded);
        if ($items === []) {
            return $this->renderImportWithErrors($payload, ['Import payload must include at least one plugin.'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        $parsedPlugins = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $errors[] = $this->formatImportError($index, 'Plugin entry must be an object.');
                continue;
            }
            $parsed = $this->parseImportEntry($item, $index, $errors);
            if ($parsed !== null) {
                $parsedPlugins[] = $parsed;
            }
        }

        if ($errors !== []) {
            return $this->renderImportWithErrors($payload, $errors, Response::HTTP_BAD_REQUEST);
        }

        foreach ($parsedPlugins as $pluginData) {
            $plugin = $this->pluginRepository->findDuplicateForGameKey($pluginData['game_key'], $pluginData['name'], $pluginData['version']);
            $source = 'import';

            if ($plugin === null) {
                $plugin = new GamePlugin(
                    template: $pluginData['template'],
                    name: $pluginData['name'],
                    version: $pluginData['version'],
                    checksum: $pluginData['checksum'],
                    downloadUrl: $pluginData['download_url'],
                    description: $pluginData['description'],
                );
                $this->entityManager->persist($plugin);
                $source = 'import_create';
            } else {
                $plugin->setTemplate($pluginData['template']);
                $plugin->setChecksum($pluginData['checksum']);
                $plugin->setDownloadUrl($pluginData['download_url']);
                $plugin->setDescription($pluginData['description']);
                $source = 'import_update';
            }

            $this->entityManager->flush();

            $this->auditLogger->log($actor, 'plugin.created', [
                'plugin_id' => $plugin->getId(),
                'template_id' => $plugin->getTemplate()->getId(),
                'name' => $plugin->getName(),
                'version' => $plugin->getVersion(),
                'checksum' => $plugin->getChecksum(),
                'download_url' => $plugin->getDownloadUrl(),
                'source' => $source,
            ]);
            $this->entityManager->flush();
        }

        $response = new Response($this->twig->render('admin/plugins/_import.html.twig', [
            'import' => $this->buildImportContext([
                'success_message' => sprintf('Imported %d plugin(s).', count($parsedPlugins)),
                'payload' => '',
            ]),
        ]));
        $response->headers->set('HX-Trigger', 'plugins-changed');

        return $response;
    }

    private function parsePayload(Request $request): array
    {
        $errors = [];

        $gameKey = strtolower(trim((string) $request->request->get('game_key', '')));
        $name = trim((string) $request->request->get('name', ''));
        $version = trim((string) $request->request->get('version', ''));
        $checksum = trim((string) $request->request->get('checksum', ''));
        $downloadUrl = trim((string) $request->request->get('download_url', ''));
        $description = trim((string) $request->request->get('description', ''));

        if ($gameKey === '') {
            $errors[] = 'Game key is required.';
        }
        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($version === '') {
            $errors[] = 'Version is required.';
        }
        $checksumError = $this->validateChecksum($checksum);
        if ($checksumError !== null) {
            $errors[] = $checksumError;
        }
        if ($downloadUrl === '') {
            $errors[] = 'Download URL is required.';
        } elseif (!$this->isValidDownloadUrl($downloadUrl)) {
            $errors[] = 'Download URL must be valid.';
        }

        return [
            'errors' => $errors,
            'game_key' => $gameKey,
            'name' => $name,
            'version' => $version,
            'checksum' => $checksum,
            'download_url' => $downloadUrl,
            'description' => $description !== '' ? $description : null,
        ];
    }

    private function buildFormContext(?GamePlugin $plugin = null, ?array $override = null): array
    {
        $data = [
            'id' => $plugin?->getId(),
            'game_key' => strtolower(trim($plugin?->getTemplate()->getGameKey() ?? '')),
            'name' => $plugin?->getName() ?? '',
            'version' => $plugin?->getVersion() ?? '',
            'checksum' => $plugin?->getChecksum() ?? '',
            'download_url' => $plugin?->getDownloadUrl() ?? '',
            'description' => $plugin?->getDescription() ?? '',
            'errors' => [],
            'action' => $plugin === null ? 'create' : 'update',
            'submit_label' => $plugin === null ? 'admin_plugins_create_button' : 'admin_plugins_update_button',
            'submit_color' => $plugin === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $plugin === null ? '/admin/plugins' : sprintf('/admin/plugins/%d', $plugin->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function buildImportContext(?array $overrides = null): array
    {
        $defaults = [
            'errors' => [],
            'payload' => '',
            'success_message' => '',
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function renderFormWithErrors(array $formData, int $status, ?GamePlugin $plugin = null): Response
    {
        $formContext = $this->buildFormContext($plugin, [
            'game_key' => $formData['game_key'],
            'name' => $formData['name'],
            'version' => $formData['version'],
            'checksum' => $formData['checksum'],
            'download_url' => $formData['download_url'],
            'description' => $formData['description'] ?? '',
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/plugins/_form.html.twig', [
            'form' => $formContext,
            'templates' => $this->normalizeTemplates($this->templateRepository->findBy([], ['displayName' => 'ASC'])),
        ]), $status);
    }

    private function renderImportWithErrors(string $payload, array $errors, int $status): Response
    {
        return new Response($this->twig->render('admin/plugins/_import.html.twig', [
            'import' => $this->buildImportContext([
                'errors' => $errors,
                'payload' => $payload,
            ]),
        ]), $status);
    }

    private function parseImportEntry(array $entry, int $index, array &$errors): ?array
    {
        $gameKey = strtolower(trim((string) ($entry['game_key'] ?? ($entry['template_game_key'] ?? ''))));
        $name = trim((string) ($entry['name'] ?? ''));
        $version = trim((string) ($entry['version'] ?? ''));
        $checksum = trim((string) ($entry['checksum'] ?? ''));
        $downloadUrl = trim((string) ($entry['download_url'] ?? ''));
        $description = trim((string) ($entry['description'] ?? ''));

        $entryErrors = [];
        $template = null;
        if ($gameKey !== '') {
            $template = $this->resolveTemplateForGameKey($gameKey);
            if ($template === null) {
                $entryErrors[] = 'Template game_key was not found.';
            }
        } else {
            $entryErrors[] = 'Template reference is required (game_key/template_game_key).';
        }

        if ($name === '') {
            $entryErrors[] = 'Name is required.';
        }
        if ($version === '') {
            $entryErrors[] = 'Version is required.';
        }
        $checksumError = $this->validateChecksum($checksum);
        if ($checksumError !== null) {
            $entryErrors[] = $checksumError;
        }
        if ($downloadUrl === '') {
            $entryErrors[] = 'Download URL is required.';
        } elseif (!$this->isValidDownloadUrl($downloadUrl)) {
            $entryErrors[] = 'Download URL must be valid.';
        }

        if ($entryErrors !== []) {
            foreach ($entryErrors as $entryError) {
                $errors[] = $this->formatImportError($index, $entryError);
            }

            return null;
        }

        return [
            'template' => $template,
            'game_key' => $gameKey,
            'name' => $name,
            'version' => $version,
            'checksum' => $checksum,
            'download_url' => $downloadUrl,
            'description' => $description !== '' ? $description : null,
        ];
    }

    private function validateChecksum(string $checksum): ?string
    {
        if ($checksum === '') {
            return null;
        }

        if (preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{40}|[a-f0-9]{64}|[a-f0-9]{128})\z/i', $checksum) === 1) {
            return null;
        }

        return 'Checksum must be empty or a valid MD5, SHA1, SHA256, or SHA512 hex digest.';
    }

    private function isValidDownloadUrl(string $downloadUrl): bool
    {
        if (filter_var($downloadUrl, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        return $this->isValidGithubLatestReleaseAssetUrl($downloadUrl);
    }

    private function isValidGithubLatestReleaseAssetUrl(string $downloadUrl): bool
    {
        if (!preg_match('#^github://([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+)/releases/latest\?(.*)$#', $downloadUrl, $matches)) {
            return false;
        }

        parse_str($matches[3], $query);
        $assetPattern = is_string($query['asset'] ?? null) ? trim($query['asset']) : '';

        return $assetPattern !== '';
    }

    private function formatImportError(int $index, string $message): string
    {
        return sprintf('Entry %d: %s', $index + 1, $message);
    }

    private function buildEmptyMessage(): string
    {
        if ($this->templateRepository->count([]) === 0) {
            return $this->translator->trans('admin_plugins_empty_missing_templates');
        }

        return $this->translator->trans('admin_plugins_empty');
    }

    /**
     * @param GamePlugin[] $plugins
     */
    private function buildSummary(array $plugins): array
    {
        $summary = [
            'total' => count($plugins),
            'game_keys' => [],
        ];

        foreach ($plugins as $plugin) {
            $gameKey = strtolower(trim($plugin->getTemplate()->getGameKey()));
            if ($gameKey === '') {
                continue;
            }
            $summary['game_keys'][$gameKey] = true;
        }

        $summary['game_keys'] = count($summary['game_keys']);

        return $summary;
    }

    /**
     * @param GamePlugin[] $plugins
     */
    private function normalizePlugins(array $plugins): array
    {
        return array_map(static function (GamePlugin $plugin): array {
            return [
                'id' => $plugin->getId(),
                'name' => $plugin->getName(),
                'version' => $plugin->getVersion(),
                'checksum' => $plugin->getChecksum(),
                'download_url' => $plugin->getDownloadUrl(),
                'description' => $plugin->getDescription(),
                'template' => [
                    'id' => $plugin->getTemplate()->getId(),
                    'name' => $plugin->getTemplate()->getDisplayName(),
                    'game_key' => strtolower(trim($plugin->getTemplate()->getGameKey())),
                ],
                'updated_at' => $plugin->getUpdatedAt(),
            ];
        }, $plugins);
    }

    private function normalizePlugin(GamePlugin $plugin): array
    {
        return [
            'id' => $plugin->getId(),
            'name' => $plugin->getName(),
            'version' => $plugin->getVersion(),
            'checksum' => $plugin->getChecksum(),
            'download_url' => $plugin->getDownloadUrl(),
            'description' => $plugin->getDescription(),
            'template' => [
                'id' => $plugin->getTemplate()->getId(),
                'name' => $plugin->getTemplate()->getDisplayName(),
                'game_key' => strtolower(trim($plugin->getTemplate()->getGameKey())),
            ],
        ];
    }

    private function normalizeTemplates(array $templates): array
    {
        $distinct = [];
        foreach ($templates as $template) {
            $gameKey = strtolower(trim((string) $template->getGameKey()));
            if ($gameKey === '' || isset($distinct[$gameKey])) {
                continue;
            }

            $distinct[$gameKey] = [
                'game_key' => $gameKey,
                'label' => sprintf('%s (%s)', $template->getDisplayName(), $gameKey),
            ];
        }

        ksort($distinct);

        return array_values($distinct);
    }

    private function resolveTemplateForGameKey(string $gameKey): ?\App\Module\Core\Domain\Entity\Template
    {
        $normalized = strtolower(trim($gameKey));
        if ($normalized === '') {
            return null;
        }

        return $this->templateRepository->findOneBy(['gameKey' => $normalized]);
    }


    /**
     * @return array<int, array{game_key:string,name:string,version:string,checksum:string,download_url:string,description:string}>
     */
    private function buildRecommendedSeedEntries(): array
    {
        return array_map(static function (array $entry): array {
            return [
                'game_key' => (string) ($entry['template_game_key'] ?? ''),
                'name' => (string) ($entry['name'] ?? ''),
                'version' => (string) ($entry['version'] ?? ''),
                'checksum' => (string) ($entry['checksum'] ?? ''),
                'download_url' => (string) ($entry['download_url'] ?? ''),
                'description' => (string) ($entry['description'] ?? ''),
            ];
        }, (new GamePluginSeedCatalog())->listPlugins());
    }


    private function isAdmin(Request $request): bool
    {
        $user = $request->attributes->get('current_user');

        return $user instanceof User && $user->isAdmin();
    }
}
