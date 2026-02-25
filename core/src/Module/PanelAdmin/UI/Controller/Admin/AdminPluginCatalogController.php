<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\GamePlugin;
use App\Module\Core\Domain\Entity\User;
use App\Repository\GamePluginRepository;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/plugins')]
final class AdminPluginCatalogController
{
    public function __construct(
        private readonly GamePluginRepository $pluginRepository,
        private readonly TemplateRepository $templateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_plugins', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $plugins = $this->pluginRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/plugins/index.html.twig', [
            'plugins' => $this->normalizePlugins($plugins),
            'summary' => $this->buildSummary($plugins),
            'activeNav' => 'plugins',
        ]));
    }

    #[Route(path: '/new', name: 'admin_plugins_new', methods: ['GET'])]
    public function createPage(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
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
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
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
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $plugins = $this->pluginRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/plugins/_table.html.twig', [
            'plugins' => $this->normalizePlugins($plugins),
        ]));
    }

    #[Route(path: '/form', name: 'admin_plugins_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $templates = $this->templateRepository->findBy([], ['displayName' => 'ASC']);

        return new Response($this->twig->render('admin/plugins/_form.html.twig', [
            'form' => $this->buildFormContext(),
            'templates' => $this->normalizeTemplates($templates),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_plugins_edit', methods: ['GET'])]
    public function editPage(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $plugin = $this->pluginRepository->find($id);
        if ($plugin === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $templates = $this->templateRepository->findBy([], ['displayName' => 'ASC']);

        return new Response($this->twig->render('admin/plugins/edit.html.twig', [
            'form' => $this->buildFormContext($plugin),
            'templates' => $this->normalizeTemplates($templates),
            'activeNav' => 'plugins',
        ]));
    }

    #[Route(path: '/{id}/preview', name: 'admin_plugins_preview', methods: ['GET'])]
    public function previewPage(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $plugin = $this->pluginRepository->find($id);
        if ($plugin === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
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
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
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

    #[Route(path: '/{id}', name: 'admin_plugins_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $plugin = $this->pluginRepository->find($id);
        if ($plugin === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
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

    #[Route(path: '/{id}/delete', name: 'admin_plugins_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $plugin = $this->pluginRepository->find($id);
        if ($plugin === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
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
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $seedEntries = $this->buildRecommendedSeedEntries();
        $imported = 0;
        $updated = 0;

        foreach ($seedEntries as $entry) {
            $template = $this->resolveTemplateForGameKey($entry['game_key']);
            if ($template === null) {
                continue;
            }

            $existing = $this->pluginRepository->findDuplicateForGameKey($entry['game_key'], $entry['name'], $entry['version']);
            if ($existing === null) {
                $plugin = new GamePlugin(
                    template: $template,
                    name: $entry['name'],
                    version: $entry['version'],
                    checksum: $entry['checksum'],
                    downloadUrl: $entry['download_url'],
                    description: $entry['description'],
                );
                $this->entityManager->persist($plugin);
                $imported++;
            } else {
                $existing->setTemplate($template);
                $existing->setChecksum($entry['checksum']);
                $existing->setDownloadUrl($entry['download_url']);
                $existing->setDescription($entry['description']);
                $updated++;
            }
        }

        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'plugin.seeded', [
            'imported' => $imported,
            'updated' => $updated,
            'entries' => count($seedEntries),
        ]);

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'plugins-changed');

        return $response;
    }

    #[Route(path: '/import', name: 'admin_plugins_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
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
        if ($checksum === '') {
            $errors[] = 'Checksum is required.';
        }
        if ($downloadUrl === '') {
            $errors[] = 'Download URL is required.';
        } elseif (!filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
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
        if ($checksum === '') {
            $entryErrors[] = 'Checksum is required.';
        }
        if ($downloadUrl === '') {
            $entryErrors[] = 'Download URL is required.';
        } elseif (!filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
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

    private function formatImportError(int $index, string $message): string
    {
        return sprintf('Entry %d: %s', $index + 1, $message);
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
        return [
            [
                'game_key' => 'cs2',
                'name' => 'Metamod:Source',
                'version' => '2.0-stable',
                'checksum' => 'manual-verification-required',
                'download_url' => 'https://mms.alliedmods.net/mmsdrop/2.0/mmsource-latest-linux',
                'description' => 'Core mod loader for CS2/Source2. Nach Installation muss game/csgo/gameinfo.gi den Eintrag "Game csgo/addons/metamod" enthalten (zwischen Game_LowViolence csgo_lv und Game csgo).',
            ],
            [
                'game_key' => 'cs2',
                'name' => 'CounterStrikeSharp',
                'version' => 'latest',
                'checksum' => 'manual-verification-required',
                'download_url' => 'https://github.com/roflmuffin/CounterStrikeSharp/releases/latest/download/counterstrikesharp-with-runtime-build.zip',
                'description' => 'CS2 plugin framework for C# plugins.',
            ],
            [
                'game_key' => 'rust',
                'name' => 'uMod/Oxide for Rust',
                'version' => 'latest',
                'checksum' => 'manual-verification-required',
                'download_url' => 'https://github.com/OxideMod/Oxide.Rust/releases/latest/download/Oxide.Rust-linux.zip',
                'description' => 'Most used Rust plugin framework (uMod/Oxide).',
            ],
            [
                'game_key' => 'rust',
                'name' => 'Carbon for Rust',
                'version' => 'latest',
                'checksum' => 'manual-verification-required',
                'download_url' => 'https://github.com/CarbonCommunity/Carbon/releases/latest/download/Carbon.Linux.Release.tar.gz',
                'description' => 'Alternative Rust mod framework with Oxide compatibility layer.',
            ],
            [
                'game_key' => 'minecraft',
                'name' => 'LuckPerms',
                'version' => 'latest',
                'checksum' => 'manual-verification-required',
                'download_url' => 'https://download.luckperms.net/1565/bukkit/loader/LuckPerms-Bukkit-5.4.121.jar',
                'description' => 'Popular permissions plugin for Spigot/Paper based servers.',
            ],
            [
                'game_key' => 'minecraft',
                'name' => 'EssentialsX',
                'version' => 'latest',
                'checksum' => 'manual-verification-required',
                'download_url' => 'https://github.com/EssentialsX/Essentials/releases/latest/download/EssentialsX-2.21.0.jar',
                'description' => 'Popular essentials command/admin plugin for Bukkit/Paper.',
            ],
            [
                'game_key' => 'tf2',
                'name' => 'SourceMod',
                'version' => 'latest',
                'checksum' => 'manual-verification-required',
                'download_url' => 'https://sm.alliedmods.net/smdrop/1.12/sourcemod-latest-linux',
                'description' => 'TF2 plugin framework (requires Metamod).',
            ],
            [
                'game_key' => 'gmod',
                'name' => 'ULX Admin Mod',
                'version' => 'latest',
                'checksum' => 'manual-verification-required',
                'download_url' => 'https://github.com/TeamUlysses/ulx/archive/refs/heads/master.zip',
                'description' => 'Popular admin mod for Garry\'s Mod (paired with ULib).',
            ],
        ];
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->attributes->get('current_user');

        return $user instanceof User && $user->isAdmin();
    }
}
