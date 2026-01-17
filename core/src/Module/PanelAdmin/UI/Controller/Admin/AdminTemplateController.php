<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\TemplateRepository;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/templates')]
final class AdminTemplateController
{
    public function __construct(
        private readonly TemplateRepository $templateRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_templates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $templates = $this->templateRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/templates/index.html.twig', [
            'templates' => $this->normalizeTemplates($templates),
            'summary' => $this->buildSummary($templates),
            'activeNav' => 'templates',
        ]));
    }

    #[Route(path: '/new', name: 'admin_templates_new', methods: ['GET'])]
    public function createPage(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/templates/create.html.twig', [
            'form' => $this->buildFormContext(),
            'activeNav' => 'templates',
        ]));
    }

    #[Route(path: '/{id<\d+>}/edit', name: 'admin_templates_edit', methods: ['GET'])]
    public function editPage(Request $request, string $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new Response('Template not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/templates/edit.html.twig', [
            'form' => $this->buildFormContextFromTemplate($template),
            'activeNav' => 'templates',
        ]));
    }

    #[Route(path: '/import', name: 'admin_templates_import_page', methods: ['GET'])]
    public function importPage(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/templates/import.html.twig', [
            'import' => $this->buildImportContext(),
            'activeNav' => 'templates',
        ]));
    }

    #[Route(path: '/{id<\d+>}/preview', name: 'admin_templates_preview_page', methods: ['GET'])]
    public function previewPage(Request $request, string $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new Response('Template not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/templates/preview.html.twig', [
            'preview' => $this->buildPreviewFromTemplate($template),
            'template_id' => $template->getId(),
            'activeNav' => 'templates',
        ]));
    }

    #[Route(path: '/table', name: 'admin_templates_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $templates = $this->templateRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/templates/_table.html.twig', [
            'templates' => $this->normalizeTemplates($templates),
        ]));
    }

    #[Route(path: '/form', name: 'admin_templates_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $templateId = (int) $request->query->get('id', 0);
        if ($templateId > 0) {
            $template = $this->templateRepository->find($templateId);
            if ($template === null) {
                return new Response($this->twig->render('admin/templates/_form.html.twig', [
                    'form' => $this->buildFormContext([
                        'errors' => ['Template not found.'],
                    ]),
                ]), Response::HTTP_NOT_FOUND);
            }

            return new Response($this->twig->render('admin/templates/_form.html.twig', [
                'form' => $this->buildFormContextFromTemplate($template),
            ]));
        }

        return new Response($this->twig->render('admin/templates/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/preview', name: 'admin_templates_preview', methods: ['GET', 'POST'])]
    public function preview(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $preview = [
            'errors' => [],
        ];

        $templateId = (int) $request->query->get('id', 0);
        if ($request->isMethod('GET') && $templateId > 0) {
            $template = $this->templateRepository->find($templateId);
            if ($template === null) {
                $preview['errors'][] = 'Template not found.';
            } else {
                $preview = $this->buildPreviewFromTemplate($template);
            }
        } elseif ($request->isMethod('POST')) {
            $formData = $this->parsePayload($request);
            $preview = $this->buildPreviewFromForm($formData);
        }

        return new Response($this->twig->render('admin/templates/_preview.html.twig', [
            'preview' => $preview,
        ]));
    }

    #[Route(path: '', name: 'admin_templates_create', methods: ['POST'])]
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

        $template = new Template(
            $formData['game_key'],
            $formData['display_name'],
            $formData['description'],
            $formData['steam_app_id'],
            $formData['sniper_profile'],
            $formData['required_ports'],
            $formData['start_params'],
            $formData['env_vars'],
            $formData['config_files'],
            $formData['plugin_paths'],
            $formData['fastdl_settings'],
            $formData['install_command'],
            $formData['update_command'],
            $formData['install_resolver'],
            $formData['allowed_switch_flags'],
            $formData['requirement_vars_parsed'],
            $formData['requirement_secrets_parsed'],
            $this->resolveSupportedOs($formData['game_key']),
            $this->buildPortProfile($formData['required_ports']),
            $this->buildRequirements(
                $formData['game_key'],
                $formData['steam_app_id'],
                $formData['env_vars'],
                $formData['requirement_vars_parsed'],
                $formData['requirement_secrets_parsed'],
            ),
        );

        $this->entityManager->persist($template);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'template.created', [
            'template_id' => $template->getId(),
            'game_key' => $template->getGameKey(),
            'display_name' => $template->getDisplayName(),
            'required_ports' => $template->getRequiredPorts(),
            'start_params' => $template->getStartParams(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/templates/_form.html.twig', [
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'templates-changed');

        return $response;
    }

    #[Route(path: '/{id<\d+>}', name: 'admin_templates_update', methods: ['POST'])]
    public function update(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $template = $this->templateRepository->find($id);
        if ($template === null) {
            return new Response('Template not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request, $template);
        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($formData, Response::HTTP_BAD_REQUEST);
        }

        $template->setGameKey($formData['game_key']);
        $template->setDisplayName($formData['display_name']);
        $template->setDescription($formData['description']);
        $template->setSteamAppId($formData['steam_app_id']);
        $template->setSniperProfile($formData['sniper_profile']);
        $template->setRequiredPorts($formData['required_ports']);
        $template->setStartParams($formData['start_params']);
        $template->setEnvVars($formData['env_vars']);
        $template->setConfigFiles($formData['config_files']);
        $template->setPluginPaths($formData['plugin_paths']);
        $template->setFastdlSettings($formData['fastdl_settings']);
        $template->setInstallCommand($formData['install_command']);
        $template->setUpdateCommand($formData['update_command']);
        $template->setInstallResolver($formData['install_resolver']);
        $template->setAllowedSwitchFlags($formData['allowed_switch_flags']);
        $template->setRequirementVars($formData['requirement_vars_parsed']);
        $template->setRequirementSecrets($formData['requirement_secrets_parsed']);
        $template->setSupportedOs($this->resolveSupportedOs($formData['game_key']));
        $template->setPortProfile($this->buildPortProfile($formData['required_ports']));
        $template->setRequirements($this->buildRequirements(
            $formData['game_key'],
            $formData['steam_app_id'],
            $formData['env_vars'],
            $formData['requirement_vars_parsed'],
            $formData['requirement_secrets_parsed'],
        ));
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'template.updated', [
            'template_id' => $template->getId(),
            'game_key' => $template->getGameKey(),
            'display_name' => $template->getDisplayName(),
            'required_ports' => $template->getRequiredPorts(),
            'start_params' => $template->getStartParams(),
        ]);
        $this->entityManager->flush();

        $response = new Response($this->twig->render('admin/templates/_form.html.twig', [
            'form' => $this->buildFormContextFromTemplate($template),
        ]));
        $response->headers->set('HX-Trigger', 'templates-changed');

        return $response;
    }

    #[Route(path: '/import', name: 'admin_templates_import', methods: ['POST'])]
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
            return $this->renderImportWithErrors($payload, ['Import payload must include at least one template.'], Response::HTTP_BAD_REQUEST);
        }

        $errors = [];
        $parsedTemplates = [];
        $seenGameKeys = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $errors[] = $this->formatImportError($index, 'Template entry must be an object.');
                continue;
            }
            $parsed = $this->parseImportEntry($item, $index, $seenGameKeys, $errors);
            if ($parsed !== null) {
                $parsedTemplates[] = $parsed;
            }
        }

        if ($errors !== []) {
            return $this->renderImportWithErrors($payload, $errors, Response::HTTP_BAD_REQUEST);
        }

        foreach ($parsedTemplates as $templateData) {
            $template = new Template(
                $templateData['game_key'],
                $templateData['display_name'],
                $templateData['description'],
                $templateData['steam_app_id'],
                $templateData['sniper_profile'],
                $templateData['required_ports'],
                $templateData['start_params'],
                $templateData['env_vars'],
                $templateData['config_files'],
                $templateData['plugin_paths'],
                $templateData['fastdl_settings'],
                $templateData['install_command'],
                $templateData['update_command'],
                $templateData['install_resolver'],
                $templateData['allowed_switch_flags'],
                $templateData['requirement_vars'],
                $templateData['requirement_secrets'],
                $this->resolveSupportedOs($templateData['game_key']),
                $this->buildPortProfile($templateData['required_ports']),
                $this->buildRequirements(
                    $templateData['game_key'],
                    $templateData['steam_app_id'],
                    $templateData['env_vars'],
                    $templateData['requirement_vars'],
                    $templateData['requirement_secrets'],
                ),
            );

            $this->entityManager->persist($template);
            $this->entityManager->flush();

            $this->auditLogger->log($actor, 'template.created', [
                'template_id' => $template->getId(),
                'game_key' => $template->getGameKey(),
                'display_name' => $template->getDisplayName(),
                'required_ports' => $template->getRequiredPorts(),
                'start_params' => $template->getStartParams(),
                'source' => 'import',
            ]);
            $this->entityManager->flush();
        }

        $response = new Response($this->twig->render('admin/templates/_import.html.twig', [
            'import' => $this->buildImportContext([
                'success_message' => sprintf('Imported %d template(s).', count($parsedTemplates)),
                'payload' => '',
            ]),
        ]));
        $response->headers->set('HX-Trigger', 'templates-changed');

        return $response;
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }

    private function buildSummary(array $templates): array
    {
        $requiredPorts = 0;
        foreach ($templates as $template) {
            $requiredPorts += count($template->getRequiredPorts());
        }

        return [
            'total' => count($templates),
            'ports' => $requiredPorts,
            'commands' => count(array_filter($templates, fn (Template $template) => $template->getInstallCommand() !== '')),
        ];
    }

    private function normalizeTemplates(array $templates): array
    {
        return array_map(static function (Template $template): array {
            return [
                'id' => $template->getId(),
                'game_key' => $template->getGameKey(),
                'display_name' => $template->getDisplayName(),
                'description' => $template->getDescription(),
                'start_params' => $template->getStartParams(),
                'required_ports' => $template->getRequiredPorts(),
                'required_port_labels' => $template->getRequiredPortLabels(),
                'steam_app_id' => $template->getSteamAppId(),
                'sniper_profile' => $template->getSniperProfile(),
                'env_vars' => $template->getEnvVars(),
                'config_files' => $template->getConfigFiles(),
                'plugin_paths' => $template->getPluginPaths(),
                'fastdl_settings' => $template->getFastdlSettings(),
                'install_command' => $template->getInstallCommand(),
                'update_command' => $template->getUpdateCommand(),
                'allowed_switch_flags' => $template->getAllowedSwitchFlags(),
                'supported_os' => $template->getSupportedOs(),
                'updated_at' => $template->getUpdatedAt(),
            ];
        }, $templates);
    }

    private function buildFormContext(?array $overrides = null): array
    {
        $defaults = [
            'errors' => [],
            'template_id' => null,
            'is_edit' => false,
            'reset_url' => '/admin/templates/form',
            'preview_url' => null,
            'game_key' => '',
            'display_name' => '',
            'description' => '',
            'steam_app_id' => '',
            'sniper_profile' => '',
            'start_params' => '',
            'required_ports' => [],
            'env_vars' => '',
            'config_files' => '',
            'plugin_paths' => '',
            'fastdl_enabled' => false,
            'fastdl_base_url' => '',
            'fastdl_root_path' => '',
            'install_command' => '',
            'update_command' => '',
            'install_resolver' => '',
            'allowed_switch_flags' => '',
            'requirement_vars' => '',
            'requirement_secrets' => '',
        ];

        return array_merge($defaults, $overrides ?? []);
    }

    private function buildFormContextFromTemplate(Template $template): array
    {
        $fastdlSettings = $template->getFastdlSettings();

        return $this->buildFormContext([
            'template_id' => $template->getId(),
            'is_edit' => true,
            'reset_url' => sprintf('/admin/templates/form?id=%d', $template->getId()),
            'preview_url' => sprintf('/admin/templates/%d/preview', $template->getId()),
            'game_key' => $template->getGameKey(),
            'display_name' => $template->getDisplayName(),
            'description' => $template->getDescription() ?? '',
            'steam_app_id' => $template->getSteamAppId() !== null ? (string) $template->getSteamAppId() : '',
            'sniper_profile' => $template->getSniperProfile() ?? '',
            'start_params' => $template->getStartParams(),
            'required_ports' => array_map(static fn (array $port): string => (string) ($port['name'] ?? ''), $template->getRequiredPorts()),
            'env_vars' => $this->normalizeEnvVarsInput($template->getEnvVars()),
            'config_files' => $this->normalizeConfigFilesInput($template->getConfigFiles()),
            'plugin_paths' => $this->normalizeLinesInput($template->getPluginPaths()),
            'fastdl_enabled' => (bool) ($fastdlSettings['enabled'] ?? false),
            'fastdl_base_url' => (string) ($fastdlSettings['base_url'] ?? ''),
            'fastdl_root_path' => (string) ($fastdlSettings['root_path'] ?? ''),
            'install_command' => $template->getInstallCommand(),
            'update_command' => $template->getUpdateCommand(),
            'install_resolver' => $this->normalizeJsonInput($template->getInstallResolver()),
            'allowed_switch_flags' => implode(',', $template->getAllowedSwitchFlags()),
            'requirement_vars' => $this->normalizeJsonInput($template->getRequirementVars()),
            'requirement_secrets' => $this->normalizeJsonInput($template->getRequirementSecrets()),
        ]);
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

    private function parsePayload(Request $request, ?Template $existingTemplate = null): array
    {
        $errors = [];
        $gameKey = trim((string) $request->request->get('game_key', ''));
        $displayName = trim((string) $request->request->get('display_name', ''));
        $description = trim((string) $request->request->get('description', ''));
        $steamAppIdRaw = trim((string) $request->request->get('steam_app_id', ''));
        $sniperProfile = trim((string) $request->request->get('sniper_profile', ''));
        $startParams = trim((string) $request->request->get('start_params', ''));
        $requiredPortsRaw = $request->request->all('required_ports');
        if (!is_array($requiredPortsRaw)) {
            $requiredPortsRaw = [];
        }
        $envVarsRaw = trim((string) $request->request->get('env_vars', ''));
        $configFilesRaw = trim((string) $request->request->get('config_files', ''));
        $pluginPathsRaw = trim((string) $request->request->get('plugin_paths', ''));
        $fastdlEnabled = (bool) $request->request->get('fastdl_enabled', false);
        $fastdlBaseUrl = trim((string) $request->request->get('fastdl_base_url', ''));
        $fastdlRootPath = trim((string) $request->request->get('fastdl_root_path', ''));
        $installCommand = trim((string) $request->request->get('install_command', ''));
        $updateCommand = trim((string) $request->request->get('update_command', ''));
        $installResolverRaw = $request->request->get('install_resolver', '');
        $allowedSwitchFlagsRaw = $request->request->get('allowed_switch_flags', '');
        $requirementVarsRaw = $request->request->get('requirement_vars', '');
        $requirementSecretsRaw = $request->request->get('requirement_secrets', '');
        // Array to string conversion guard (HTMX/JSON payloads may submit arrays)
        $installResolverRaw = is_array($installResolverRaw) ? (string) json_encode($installResolverRaw, JSON_UNESCAPED_SLASHES) : trim((string) $installResolverRaw);
        $allowedSwitchFlagsRaw = is_array($allowedSwitchFlagsRaw) ? implode(',', array_map('strval', $allowedSwitchFlagsRaw)) : trim((string) $allowedSwitchFlagsRaw);
        $requirementVarsRaw = is_array($requirementVarsRaw) ? (string) json_encode($requirementVarsRaw, JSON_UNESCAPED_SLASHES) : trim((string) $requirementVarsRaw);
        $requirementSecretsRaw = is_array($requirementSecretsRaw) ? (string) json_encode($requirementSecretsRaw, JSON_UNESCAPED_SLASHES) : trim((string) $requirementSecretsRaw);


        if ($gameKey === '') {
            $errors[] = 'Game key is required.';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9_.-]+$/', $gameKey)) {
            $errors[] = 'Game key must be lowercase and contain only letters, numbers, dots, dashes, or underscores.';
        } elseif ($this->isDuplicateGameKey($gameKey, $existingTemplate)) {
            $errors[] = 'Game key must be unique.';
        }
        if ($displayName === '') {
            $errors[] = 'Display name is required.';
        }
        if ($startParams === '') {
            $errors[] = 'Start params are required.';
        }
        if ($installCommand === '') {
            $errors[] = 'Install command is required.';
        }
        if ($updateCommand === '') {
            $errors[] = 'Update command is required.';
        }

        $steamAppId = null;
        if ($steamAppIdRaw !== '') {
            if (!ctype_digit($steamAppIdRaw)) {
                $errors[] = 'Steam App ID must be numeric.';
            } else {
                $steamAppId = (int) $steamAppIdRaw;
                if ($steamAppId <= 0) {
                    $errors[] = 'Steam App ID must be positive.';
                }
            }
        }

        $entryErrors = [];
        $requiredPorts = $this->parseRequiredPorts($requiredPortsRaw, $entryErrors);
        $envVars = $this->parseEnvVars($envVarsRaw, $entryErrors);
        $configFiles = $this->parseConfigFiles($configFilesRaw, $entryErrors);
        $requirementVarsParsed = $this->parseRequirements($requirementVarsRaw, $entryErrors, 'vars');
        $requirementSecretsParsed = $this->parseRequirements($requirementSecretsRaw, $entryErrors, 'secrets');
        $installResolver = $this->parseInstallResolver($installResolverRaw, $entryErrors);
        foreach ($entryErrors as $entryError) {
            $errors[] = $entryError;
        }
        $pluginPaths = $this->parseLines($pluginPathsRaw);
        $allowedSwitchFlags = $this->parseList($allowedSwitchFlagsRaw);
        $fastdlSettings = [
            'enabled' => $fastdlEnabled,
            'base_url' => $fastdlBaseUrl,
            'root_path' => $fastdlRootPath,
        ];
        if ($fastdlEnabled && $fastdlBaseUrl === '') {
            $errors[] = 'FastDL base URL is required when FastDL is enabled.';
        }
        $this->validateMinecraftInstallResolver($gameKey, $installResolver, $errors);

        return [
            'errors' => $errors,
            'template_id' => $existingTemplate?->getId(),
            'is_edit' => $existingTemplate !== null,
            'game_key' => $gameKey,
            'display_name' => $displayName,
            'description' => $description !== '' ? $description : null,
            'steam_app_id' => $steamAppId,
            'sniper_profile' => $sniperProfile !== '' ? $sniperProfile : null,
            'start_params' => $startParams,
            'required_ports' => $requiredPorts,
            'env_vars' => $envVars,
            'config_files' => $configFiles,
            'plugin_paths' => $pluginPaths,
            'fastdl_settings' => $fastdlSettings,
            'install_command' => $installCommand,
            'update_command' => $updateCommand,
            'install_resolver' => $installResolver,
            'allowed_switch_flags' => $allowedSwitchFlags,
            'requirement_vars' => $requirementVarsRaw,
            'requirement_secrets' => $requirementSecretsRaw,
            'requirement_vars_parsed' => $requirementVarsParsed,
            'requirement_secrets_parsed' => $requirementSecretsParsed,
            'required_ports_raw' => $requiredPortsRaw,
            'allowed_switch_flags_raw' => $allowedSwitchFlagsRaw,
            'install_resolver_raw' => $installResolverRaw,
            'steam_app_id_raw' => $steamAppIdRaw,
            'env_vars_raw' => $envVarsRaw,
            'config_files_raw' => $configFilesRaw,
            'plugin_paths_raw' => $pluginPathsRaw,
            'requirement_vars_raw' => $requirementVarsRaw,
            'requirement_secrets_raw' => $requirementSecretsRaw,
            'fastdl_enabled' => $fastdlEnabled,
            'fastdl_base_url' => $fastdlBaseUrl,
            'fastdl_root_path' => $fastdlRootPath,
        ];
    }

    private function isDuplicateGameKey(string $gameKey, ?Template $existingTemplate): bool
    {
        $existing = $this->templateRepository->findOneBy(['gameKey' => $gameKey]);
        if ($existing === null) {
            return false;
        }

        if ($existingTemplate !== null && $existing->getId() === $existingTemplate->getId()) {
            return false;
        }

        return true;
    }

    private function parseImportEntry(array $entry, int $index, array &$seenGameKeys, array &$errors): ?array
    {
        $gameKey = trim((string) ($entry['game_key'] ?? ''));
        $displayName = trim((string) ($entry['display_name'] ?? ''));
        $description = trim((string) ($entry['description'] ?? ''));
        $steamAppIdRaw = trim((string) ($entry['steam_app_id'] ?? ''));
        $sniperProfile = trim((string) ($entry['sniper_profile'] ?? ''));
        $startParams = trim((string) ($entry['start_params'] ?? ''));
        $requiredPortsRaw = $entry['required_ports'] ?? [];
        $envVarsRaw = $this->normalizeEnvVarsInput($entry['env_vars'] ?? '');
        $configFilesRaw = $this->normalizeConfigFilesInput($entry['config_files'] ?? '');
        $pluginPathsRaw = $this->normalizeLinesInput($entry['plugin_paths'] ?? '');
        $fastdlSettingsInput = $entry['fastdl_settings'] ?? [];
        if (!is_array($fastdlSettingsInput)) {
            $fastdlSettingsInput = [];
        }
        $fastdlEnabled = (bool) ($entry['fastdl_enabled'] ?? ($fastdlSettingsInput['enabled'] ?? false));
        $fastdlBaseUrl = trim((string) ($entry['fastdl_base_url'] ?? ($fastdlSettingsInput['base_url'] ?? '')));
        $fastdlRootPath = trim((string) ($entry['fastdl_root_path'] ?? ($fastdlSettingsInput['root_path'] ?? '')));
        $installCommand = trim((string) ($entry['install_command'] ?? ''));
        $updateCommand = trim((string) ($entry['update_command'] ?? ''));
        $installResolverRaw = $entry['install_resolver'] ?? [];
        $allowedSwitchFlagsRaw = $this->normalizeListInput($entry['allowed_switch_flags'] ?? '');
        $requirementVarsRaw = $entry['requirement_vars'] ?? [];
        $requirementSecretsRaw = $entry['requirement_secrets'] ?? [];

        $entryErrors = [];
        if ($gameKey === '') {
            $entryErrors[] = 'Game key is required.';
        } elseif (!preg_match('/^[a-z0-9][a-z0-9_.-]+$/', $gameKey)) {
            $entryErrors[] = 'Game key must be lowercase and contain only letters, numbers, dots, dashes, or underscores.';
        } elseif (isset($seenGameKeys[$gameKey])) {
            $entryErrors[] = 'Game key must be unique inside the import.';
        } elseif ($this->templateRepository->findOneBy(['gameKey' => $gameKey]) !== null) {
            $entryErrors[] = 'Game key already exists.';
        }
        if ($displayName === '') {
            $entryErrors[] = 'Display name is required.';
        }
        if ($startParams === '') {
            $entryErrors[] = 'Start params are required.';
        }
        if ($installCommand === '') {
            $entryErrors[] = 'Install command is required.';
        }
        if ($updateCommand === '') {
            $entryErrors[] = 'Update command is required.';
        }

        $steamAppId = null;
        if ($steamAppIdRaw !== '') {
            if (!ctype_digit($steamAppIdRaw)) {
                $entryErrors[] = 'Steam App ID must be numeric.';
            } else {
                $steamAppId = (int) $steamAppIdRaw;
                if ($steamAppId <= 0) {
                    $entryErrors[] = 'Steam App ID must be positive.';
                }
            }
        }

        if (is_string($requiredPortsRaw)) {
            $requiredPortsRaw = preg_split('/[\s,]+/', $requiredPortsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($requiredPortsRaw)) {
            $requiredPortsRaw = [];
        }
        $requiredPorts = $this->parseRequiredPorts($requiredPortsRaw, $entryErrors);
        $envVars = $this->parseEnvVars($envVarsRaw, $entryErrors);
        $configFiles = $this->parseConfigFiles($configFilesRaw, $entryErrors);
        $requirementVars = $this->parseRequirements($requirementVarsRaw, $entryErrors, 'vars');
        $requirementSecrets = $this->parseRequirements($requirementSecretsRaw, $entryErrors, 'secrets');
        $installResolver = $this->parseInstallResolver($installResolverRaw, $entryErrors);
        $pluginPaths = $this->parseLines($pluginPathsRaw);
        $allowedSwitchFlags = $this->parseList($allowedSwitchFlagsRaw);
        $fastdlSettings = [
            'enabled' => $fastdlEnabled,
            'base_url' => $fastdlBaseUrl,
            'root_path' => $fastdlRootPath,
        ];
        if ($fastdlEnabled && $fastdlBaseUrl === '') {
            $entryErrors[] = 'FastDL base URL is required when FastDL is enabled.';
        }
        $this->validateMinecraftInstallResolver($gameKey, $installResolver, $entryErrors);

        if ($entryErrors !== []) {
            foreach ($entryErrors as $entryError) {
                $errors[] = $this->formatImportError($index, $entryError);
            }

            return null;
        }

        $seenGameKeys[$gameKey] = true;

        return [
            'game_key' => $gameKey,
            'display_name' => $displayName,
            'description' => $description !== '' ? $description : null,
            'steam_app_id' => $steamAppId,
            'sniper_profile' => $sniperProfile !== '' ? $sniperProfile : null,
            'start_params' => $startParams,
            'required_ports' => $requiredPorts,
            'env_vars' => $envVars,
            'config_files' => $configFiles,
            'plugin_paths' => $pluginPaths,
            'fastdl_settings' => $fastdlSettings,
            'install_command' => $installCommand,
            'update_command' => $updateCommand,
            'install_resolver' => $installResolver,
            'allowed_switch_flags' => $allowedSwitchFlags,
            'requirement_vars' => $requirementVars,
            'requirement_secrets' => $requirementSecrets,
        ];
    }

    private function normalizeJsonInput(array $value): string
    {
        return $value === [] ? '' : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<int, mixed>|string $input
     * @return array<int, array<string, mixed>>
     */
    private function parseRequirements(array|string $input, array &$errors, string $label): array
    {
        if (is_string($input)) {
            if (trim($input) === '') {
                return [];
            }

            $decoded = json_decode($input, true);
            if (!is_array($decoded)) {
                $errors[] = sprintf('Requirements %s must be valid JSON.', $label);
                return [];
            }
            $input = $decoded;
        }

        if (!is_array($input)) {
            $errors[] = sprintf('Requirements %s must be an array.', $label);
            return [];
        }

        $normalized = [];
        foreach ($input as $entry) {
            if (!is_array($entry)) {
                $errors[] = sprintf('Requirements %s entry must be an object.', $label);
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key === '') {
                $errors[] = sprintf('Requirements %s entry must include a key.', $label);
                continue;
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }

    private function parseRequiredPorts(array $portTypes, array &$errors): array
    {
        $definitions = [
            'game' => ['label' => 'Game', 'protocol' => 'udp'],
            'query' => ['label' => 'Query', 'protocol' => 'udp'],
            'rcon' => ['label' => 'RCON', 'protocol' => 'tcp'],
            'tv' => ['label' => 'SourceTV', 'protocol' => 'udp'],
        ];

        $ports = [];
        foreach ($portTypes as $entry) {
            // Accept either a simple string ("game") or an object/array (['name' => 'game', ...])
            if (is_array($entry)) {
                $entry = (string) ($entry['name'] ?? '');
            }

            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }

            if (!array_key_exists($entry, $definitions)) {
                $errors[] = sprintf('Unknown port type "%s".', $entry);
                continue;
            }

            $ports[] = [
                'name' => $entry,
                'label' => $definitions[$entry]['label'],
                'protocol' => $definitions[$entry]['protocol'],
            ];
        }

        if ($ports === [] || !in_array('game', array_column($ports, 'name'), true)) {
            $errors[] = 'Required ports must include the game port.';
        }

        $unique = [];
        foreach ($ports as $port) {
            $uniqueKey = sprintf('%s:%s', $port['name'], $port['protocol']);
            $unique[$uniqueKey] = $port;
        }

        return array_values($unique);
    }

    private function parseEnvVars(string $value, array &$errors): array
    {
        $entries = [];
        foreach ($this->parseLines($value) as $line) {
            if (!str_contains($line, '=')) {
                $errors[] = sprintf('Env var "%s" must be in KEY=VALUE format.', $line);
                continue;
            }
            [$key, $val] = array_map('trim', explode('=', $line, 2));
            if ($key === '') {
                $errors[] = sprintf('Env var "%s" is missing a key.', $line);
                continue;
            }
            $entries[] = ['key' => $key, 'value' => $val];
        }

        return $entries;
    }

    private function parseConfigFiles(string $value, array &$errors): array
    {
        $entries = [];
        foreach ($this->parseLines($value) as $line) {
            $path = $line;
            $description = null;
            if (str_contains($line, '|')) {
                [$path, $description] = array_map('trim', explode('|', $line, 2));
            }
            if ($path === '') {
                $errors[] = 'Config file paths cannot be empty.';
                continue;
            }
            $entries[] = [
                'path' => $path,
                'description' => $description,
            ];
        }

        return $entries;
    }

    private function parseLines(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $lines = preg_split('/\R/', $value);
        if ($lines === false) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $lines), static fn (string $line) => $line !== ''));
    }

    private function parseList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $items = array_filter(array_map('trim', explode(',', $value)), static fn (string $item) => $item !== '');

        return array_values(array_unique($items));
    }

    private function normalizeLinesInput(mixed $value): string
    {
        if (is_array($value)) {
            $lines = array_map(static fn ($entry): string => trim((string) $entry), $value);
            $lines = array_filter($lines, static fn (string $line): bool => $line !== '');

            return implode("\n", $lines);
        }

        return trim((string) $value);
    }

    private function normalizeListInput(mixed $value): string
    {
        if (is_array($value)) {
            $items = array_map(static fn ($entry): string => trim((string) $entry), $value);
            $items = array_filter($items, static fn (string $item): bool => $item !== '');

            return implode(',', $items);
        }

        return trim((string) $value);
    }

    private function normalizeEnvVarsInput(mixed $value): string
    {
        if (is_array($value)) {
            $lines = [];
            foreach ($value as $entry) {
                if (is_array($entry)) {
                    $key = trim((string) ($entry['key'] ?? ''));
                    $val = (string) ($entry['value'] ?? '');
                    $lines[] = $key === '' ? '' : sprintf('%s=%s', $key, $val);
                    continue;
                }
                $lines[] = (string) $entry;
            }

            return $this->normalizeLinesInput($lines);
        }

        return trim((string) $value);
    }

    private function normalizeConfigFilesInput(mixed $value): string
    {
        if (is_array($value)) {
            $lines = [];
            foreach ($value as $entry) {
                if (is_array($entry)) {
                    $path = trim((string) ($entry['path'] ?? ''));
                    $description = trim((string) ($entry['description'] ?? ''));
                    $lines[] = $description !== '' ? sprintf('%s | %s', $path, $description) : $path;
                    continue;
                }
                $lines[] = (string) $entry;
            }

            return $this->normalizeLinesInput($lines);
        }

        return trim((string) $value);
    }

    private function buildPreviewFromTemplate(Template $template): array
    {
        $preview = [
            'identity' => [
                'game_key' => $template->getGameKey(),
                'display_name' => $template->getDisplayName(),
                'steam_app_id' => $template->getSteamAppId(),
                'sniper_profile' => $template->getSniperProfile(),
            ],
            'start_params' => $template->getStartParams(),
            'required_ports' => $template->getRequiredPorts(),
            'install_command' => $template->getInstallCommand(),
            'update_command' => $template->getUpdateCommand(),
            'allowed_switch_flags' => $template->getAllowedSwitchFlags(),
            'env_vars' => $template->getEnvVars(),
            'config_files' => $template->getConfigFiles(),
            'plugin_paths' => $template->getPluginPaths(),
            'fastdl_settings' => $template->getFastdlSettings(),
            'errors' => [],
        ];

        $preview['dry_run_steps'] = $this->buildDryRunSteps($preview);

        return $preview;
    }

    private function buildPreviewFromForm(array $formData): array
    {
        $preview = [
            'identity' => [
                'game_key' => $formData['game_key'],
                'display_name' => $formData['display_name'],
                'steam_app_id' => $formData['steam_app_id'],
                'sniper_profile' => $formData['sniper_profile'],
            ],
            'start_params' => $formData['start_params'],
            'required_ports' => $formData['required_ports'],
            'install_command' => $formData['install_command'],
            'update_command' => $formData['update_command'],
            'allowed_switch_flags' => $formData['allowed_switch_flags'],
            'env_vars' => $formData['env_vars'],
            'config_files' => $formData['config_files'],
            'plugin_paths' => $formData['plugin_paths'],
            'fastdl_settings' => $formData['fastdl_settings'],
            'errors' => $formData['errors'],
        ];

        $preview['dry_run_steps'] = $this->buildDryRunSteps($preview);

        return $preview;
    }

    private function buildDryRunSteps(array $preview): array
    {
        $steps = [];
        if ($preview['install_command'] !== '') {
            $steps[] = sprintf('Run install command: %s', $preview['install_command']);
        }
        if ($preview['update_command'] !== '') {
            $steps[] = sprintf('Run update command: %s', $preview['update_command']);
        }
        if ($preview['start_params'] !== '') {
            $steps[] = sprintf('Start with params: %s', $preview['start_params']);
        }
        if (!empty($preview['required_ports'])) {
            $ports = array_map(static function (array $port): string {
                $label = $port['label'] ?? $port['name'] ?? 'port';
                $protocol = $port['protocol'] ?? 'udp';

                return sprintf('%s (%s)', $label, $protocol);
            }, $preview['required_ports']);
            $steps[] = sprintf('Reserve ports: %s', implode(', ', $ports));
        }
        if (!empty($preview['env_vars'])) {
            $envKeys = array_map(static fn (array $entry): string => (string) ($entry['key'] ?? ''), $preview['env_vars']);
            $steps[] = sprintf('Export env vars: %s', implode(', ', array_filter($envKeys)));
        }
        if (!empty($preview['config_files'])) {
            $paths = array_map(static fn (array $entry): string => (string) ($entry['path'] ?? ''), $preview['config_files']);
            $steps[] = sprintf('Write config files: %s', implode(', ', array_filter($paths)));
        }
        if (!empty($preview['plugin_paths'])) {
            $steps[] = sprintf('Ensure plugin paths: %s', implode(', ', $preview['plugin_paths']));
        }

        return $steps;
    }

    /**
     * @param array<int, array<string, mixed>> $requiredPorts
     * @return array<int, array<string, mixed>>
     */
    private function buildPortProfile(array $requiredPorts): array
    {
        $roleMap = [
            'game' => 'game',
            'query' => 'query',
            'rcon' => 'rcon',
            'tv' => 'tv',
            'voice' => 'voice',
            'filetransfer' => 'filetransfer',
        ];

        $profile = [];
        foreach ($requiredPorts as $port) {
            if (!is_array($port)) {
                continue;
            }
            $name = strtolower((string) ($port['name'] ?? 'game'));
            $role = $roleMap[$name] ?? $name;
            $protocol = (string) ($port['protocol'] ?? 'udp');
            $count = (int) ($port['count'] ?? 1);
            if ($count <= 0) {
                $count = 1;
            }

            $profile[] = [
                'role' => $role,
                'protocol' => $protocol,
                'count' => $count,
                'required' => isset($port['required']) ? (bool) $port['required'] : true,
                'contiguous' => isset($port['contiguous']) ? (bool) $port['contiguous'] : false,
            ];
        }

        return $profile;
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @param array<int, array<string, mixed>> $requirementVars
     * @param array<int, array<string, mixed>> $requirementSecrets
     * @return array<string, mixed>
     */
    private function buildRequirements(
        string $gameKey,
        ?int $steamAppId,
        array $envVars,
        array $requirementVars,
        array $requirementSecrets,
    ): array {
        $envVarKeys = $this->extractEnvVarKeys($envVars);
        $requiredVars = $this->normalizeRequirementKeys($requirementVars);
        if ($requiredVars === []) {
            $requiredVars = $envVarKeys;
        }
        $requiredSecrets = $this->normalizeSecretKeys($requirementSecrets, $gameKey);

        return [
            'required_vars' => $requiredVars,
            'required_secrets' => $requiredSecrets,
            'steam_install_mode' => $this->resolveSteamInstallMode($gameKey, $steamAppId),
            'customer_allowed_vars' => $envVarKeys,
            'customer_allowed_secrets' => $requiredSecrets,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $envVars
     * @return array<int, string>
     */
    private function extractEnvVarKeys(array $envVars): array
    {
        $keys = [];
        foreach ($envVars as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<int, array<string, mixed>> $requirementSecrets
     * @return array<int, string>
     */
    private function normalizeSecretKeys(array $requirementSecrets, string $gameKey): array
    {
        $keys = [];
        foreach ($requirementSecrets as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        if ($this->isCsTemplate($gameKey) && !in_array('STEAM_GSLT', $keys, true)) {
            $keys[] = 'STEAM_GSLT';
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<int, array<string, mixed>> $requirementVars
     * @return array<int, string>
     */
    private function normalizeRequirementKeys(array $requirementVars): array
    {
        $keys = [];
        foreach ($requirementVars as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = trim((string) ($entry['key'] ?? ''));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function resolveSupportedOs(string $gameKey): array
    {
        return str_ends_with($gameKey, '_windows') ? ['windows'] : ['linux'];
    }

    private function resolveSteamInstallMode(string $gameKey, ?int $steamAppId): string
    {
        if ($this->isMinecraftNoSteam($gameKey)) {
            return 'none';
        }

        return $steamAppId !== null ? 'anonymous' : 'none';
    }

    private function isMinecraftNoSteam(string $gameKey): bool
    {
        return in_array($gameKey, [
            'minecraft_paper',
            'minecraft_vanilla',
            'minecraft_paper_windows',
            'minecraft_vanilla_windows',
            'minecraft_paper_all',
            'minecraft_vanilla_all',
            'minecraft_bedrock',
        ], true);
    }

    private function isCsTemplate(string $gameKey): bool
    {
        return in_array($gameKey, [
            'cs2',
            'csgo_legacy',
            'cs2_windows',
            'csgo_legacy_windows',
        ], true);
    }

    private function renderFormWithErrors(array $formData, int $status): Response
    {
        return new Response($this->twig->render('admin/templates/_form.html.twig', [
            'form' => $this->buildFormContext([
                'errors' => $formData['errors'],
                'template_id' => $formData['template_id'] ?? null,
                'is_edit' => $formData['is_edit'] ?? false,
                'game_key' => $formData['game_key'],
                'display_name' => $formData['display_name'],
                'description' => $formData['description'] ?? '',
                'steam_app_id' => $formData['steam_app_id_raw'],
                'sniper_profile' => $formData['sniper_profile'] ?? '',
                'start_params' => $formData['start_params'],
                'required_ports' => $formData['required_ports_raw'],
                'env_vars' => $formData['env_vars_raw'],
                'config_files' => $formData['config_files_raw'],
                'plugin_paths' => $formData['plugin_paths_raw'],
                'fastdl_enabled' => $formData['fastdl_enabled'],
                'fastdl_base_url' => $formData['fastdl_base_url'],
                'fastdl_root_path' => $formData['fastdl_root_path'],
                'install_command' => $formData['install_command'],
                'update_command' => $formData['update_command'],
                'install_resolver' => $formData['install_resolver_raw'],
                'allowed_switch_flags' => $formData['allowed_switch_flags_raw'],
            ]),
        ]), $status);
    }

    private function renderImportWithErrors(string $payload, array $errors, int $status): Response
    {
        return new Response($this->twig->render('admin/templates/_import.html.twig', [
            'import' => $this->buildImportContext([
                'errors' => $errors,
                'payload' => $payload,
            ]),
        ]), $status);
    }

    private function parseInstallResolver(mixed $raw, array &$errors): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $rawValue = trim((string) $raw);
        if ($rawValue === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            $errors[] = 'Install resolver must be valid JSON.';
            return [];
        }

        return $decoded;
    }

    private function validateMinecraftInstallResolver(string $gameKey, array $installResolver, array &$errors): void
    {
        if (!$this->requiresMinecraftResolver($gameKey)) {
            return;
        }

        $type = trim((string) ($installResolver['type'] ?? ''));
        if ($type === '') {
            $errors[] = 'Minecraft templates require an install resolver.';
            return;
        }

        if ($gameKey === 'minecraft_vanilla_all' && $type !== 'minecraft_vanilla') {
            $errors[] = 'Minecraft Vanilla templates must use the minecraft_vanilla resolver.';
        }

        if ($gameKey === 'minecraft_paper_all' && $type !== 'papermc_paper') {
            $errors[] = 'Minecraft Paper templates must use the papermc_paper resolver.';
        }
    }

    private function requiresMinecraftResolver(string $gameKey): bool
    {
        return in_array($gameKey, [
            'minecraft_vanilla',
            'minecraft_paper',
            'minecraft_paper_windows',
            'minecraft_vanilla_windows',
            'minecraft_paper_all',
            'minecraft_vanilla_all',
        ], true);
    }

    private function formatImportError(int $index, string $message): string
    {
        return sprintf('Entry %d: %s', $index + 1, $message);
    }
}
