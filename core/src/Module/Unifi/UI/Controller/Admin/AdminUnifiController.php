<?php

declare(strict_types=1);

namespace App\Module\Unifi\UI\Controller\Admin;

use App\Message\UnifiSyncManualRulesMessage;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\User;
use App\Module\Unifi\Application\UnifiApiClient;
use App\Module\Unifi\Application\UnifiApiException;
use App\Module\Unifi\Application\UnifiPortSyncService;
use App\Module\Unifi\Domain\Entity\UnifiManualRule;
use App\Module\Unifi\Domain\Entity\UnifiPolicy;
use App\Module\Unifi\Infrastructure\Repository\UnifiAuditLogRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiManualRuleRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiPolicyRepository;
use App\Module\Unifi\Infrastructure\Repository\UnifiSettingsRepository;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/unifi')]
final class AdminUnifiController
{
    public function __construct(
        private readonly UnifiSettingsRepository $settingsRepository,
        private readonly UnifiPolicyRepository $policyRepository,
        private readonly UnifiManualRuleRepository $manualRuleRepository,
        private readonly UnifiAuditLogRepository $auditLogRepository,
        private readonly AgentRepository $agentRepository,
        private readonly EncryptionService $encryptionService,
        private readonly UnifiApiClient $apiClient,
        private readonly UnifiPortSyncService $syncService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_unifi_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $settings = $this->settingsRepository->getSettings();
        $policy = $this->policyRepository->getPolicy();
        $latestAudit = $this->auditLogRepository->findLast();

        return new Response($this->twig->render('admin/unifi/dashboard.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'settings' => $settings,
            'policy' => $policy,
            'latestAudit' => $latestAudit,
        ]));
    }

    #[Route(path: '/connection', name: 'admin_unifi_connection', methods: ['GET', 'POST'])]
    public function connection(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $settings = $this->settingsRepository->getSettings();
        $nodes = $this->agentRepository->findBy([], ['name' => 'ASC']);
        $notice = null;
        $errors = [];

        if ($request->isMethod('POST')) {
            $enabled = $request->request->get('enabled') === '1';
            $baseUrl = (string) $request->request->get('base_url', '');
            $username = (string) $request->request->get('username', '');
            $password = (string) $request->request->get('password', '');
            $verifyTls = $request->request->get('verify_tls') === '1';
            $site = (string) $request->request->get('site', '');
            $nodeTargets = $this->parseNodeTargets($request->request->all('node_targets'));

            if ($baseUrl === '') {
                $errors[] = 'Bitte die UniFi Controller URL angeben.';
            }

            if ($username === '') {
                $errors[] = 'Bitte Benutzername angeben.';
            }

            if ($errors === []) {
                $settings->setEnabled($enabled);
                $settings->setBaseUrl($baseUrl);
                $settings->setUsername($username);
                $settings->setVerifyTls($verifyTls);
                $settings->setSite($site);
                $settings->setNodeTargets($nodeTargets);

                if ($password !== '') {
                    $settings->setPasswordEncrypted($this->encryptionService->encrypt($password));
                }

                $this->entityManager->persist($settings);
                $this->entityManager->flush();
                $notice = 'Einstellungen gespeichert.';
            }
        }

        return new Response($this->twig->render('admin/unifi/connection.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'settings' => $settings,
            'nodes' => $nodes,
            'notice' => $notice,
            'errors' => $errors,
        ]));
    }

    #[Route(path: '/connection/test', name: 'admin_unifi_connection_test', methods: ['POST'])]
    public function testConnection(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $settings = $this->settingsRepository->getSettings();

        $password = $settings->getPasswordEncrypted();
        if ($password === null) {
            return $this->renderConnectionResult($admin, $settings, ['auth_failed']);
        }

        try {
            $plaintext = $this->encryptionService->decrypt($password);
            $this->apiClient->listPortForwardRules($settings, $plaintext);
            $result = ['success'];
        } catch (UnifiApiException $exception) {
            $result = [$exception->getErrorCode()];
        } catch (\RuntimeException) {
            $result = ['auth_failed'];
        }

        return $this->renderConnectionResult($admin, $settings, $result);
    }

    #[Route(path: '/policy', name: 'admin_unifi_policy', methods: ['GET', 'POST'])]
    public function policy(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $policy = $this->policyRepository->getPolicy();
        $notice = null;
        $errors = [];

        if ($request->isMethod('POST')) {
            $mode = (string) $request->request->get('mode', UnifiPolicy::MODE_AUTO);
            $allowedPorts = $this->parsePorts((string) $request->request->get('allowed_ports', ''));
            $allowedRanges = $this->parseRanges((string) $request->request->get('allowed_ranges', ''));
            $allowedProtocols = $request->request->all('allowed_protocols');
            $allowedTags = $this->parseTags((string) $request->request->get('allowed_tags', ''));

            if (!in_array($mode, [UnifiPolicy::MODE_AUTO, UnifiPolicy::MODE_MANUAL, UnifiPolicy::MODE_HYBRID], true)) {
                $errors[] = 'Ungültiger Modus.';
            }

            if ($errors === []) {
                $policy->setMode($mode);
                $policy->setAllowedPorts($allowedPorts);
                $policy->setAllowedRanges($allowedRanges);
                $policy->setAllowedProtocols($this->normalizeProtocols($allowedProtocols));
                $policy->setAllowedTags($allowedTags);

                $this->entityManager->persist($policy);
                $this->entityManager->flush();
                $notice = 'Policy gespeichert.';
            }
        }

        return new Response($this->twig->render('admin/unifi/policy.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'policy' => $policy,
            'notice' => $notice,
            'errors' => $errors,
            'protocolOptions' => ['tcp', 'udp'],
        ]));
    }

    #[Route(path: '/rules', name: 'admin_unifi_rules', methods: ['GET'])]
    public function rules(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $rules = $this->manualRuleRepository->findBy([], ['createdAt' => 'DESC']);

        return new Response($this->twig->render('admin/unifi/rules.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'rules' => $rules,
            'form' => [
                'name' => '',
                'protocol' => 'tcp',
                'port' => '',
                'target_ip' => '',
                'target_port' => '',
                'enabled' => true,
                'description' => '',
            ],
            'notice' => $request->query->get('notice'),
            'errors' => [],
            'editRule' => null,
        ]));
    }

    #[Route(path: '/rules', name: 'admin_unifi_rules_create', methods: ['POST'])]
    public function createRule(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $formData = $this->normalizeRuleForm($request);
        $errors = $formData['errors'];

        if ($errors === []) {
            $rule = new UnifiManualRule(
                $formData['name'],
                $formData['protocol'],
                $formData['port'],
                $formData['target_ip'],
                $formData['target_port'],
                $formData['description'],
                $formData['enabled'],
            );
            $this->entityManager->persist($rule);
            $this->entityManager->flush();

            $this->messageBus->dispatch(new UnifiSyncManualRulesMessage());

            return new RedirectResponse('/admin/unifi/rules?notice=created');
        }

        $rules = $this->manualRuleRepository->findBy([], ['createdAt' => 'DESC']);
        return new Response($this->twig->render('admin/unifi/rules.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'rules' => $rules,
            'form' => $formData['values'],
            'notice' => null,
            'errors' => $errors,
            'editRule' => null,
        ]), Response::HTTP_BAD_REQUEST);
    }

    #[Route(path: '/rules/{id}/edit', name: 'admin_unifi_rules_edit', methods: ['GET'])]
    public function editRule(Request $request, int $id): Response
    {
        $admin = $this->requireAdmin($request);
        $rule = $this->manualRuleRepository->find($id);
        if (!$rule instanceof UnifiManualRule) {
            return new Response('Rule not found.', Response::HTTP_NOT_FOUND);
        }

        $rules = $this->manualRuleRepository->findBy([], ['createdAt' => 'DESC']);

        return new Response($this->twig->render('admin/unifi/rules.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'rules' => $rules,
            'form' => [
                'name' => $rule->getName(),
                'protocol' => $rule->getProtocol(),
                'port' => (string) $rule->getPort(),
                'target_ip' => $rule->getTargetIp(),
                'target_port' => (string) $rule->getTargetPort(),
                'enabled' => $rule->isEnabled(),
                'description' => (string) $rule->getDescription(),
            ],
            'notice' => null,
            'errors' => [],
            'editRule' => $rule,
        ]));
    }

    #[Route(path: '/rules/{id}', name: 'admin_unifi_rules_update', methods: ['POST'])]
    public function updateRule(Request $request, int $id): Response
    {
        $admin = $this->requireAdmin($request);
        $rule = $this->manualRuleRepository->find($id);
        if (!$rule instanceof UnifiManualRule) {
            return new Response('Rule not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->normalizeRuleForm($request);
        $errors = $formData['errors'];

        if ($errors === []) {
            $rule->setName($formData['name']);
            $rule->setProtocol($formData['protocol']);
            $rule->setPort($formData['port']);
            $rule->setTargetIp($formData['target_ip']);
            $rule->setTargetPort($formData['target_port']);
            $rule->setDescription($formData['description']);
            $rule->setEnabled($formData['enabled']);

            $this->entityManager->persist($rule);
            $this->entityManager->flush();

            $this->messageBus->dispatch(new UnifiSyncManualRulesMessage());

            return new RedirectResponse('/admin/unifi/rules?notice=updated');
        }

        $rules = $this->manualRuleRepository->findBy([], ['createdAt' => 'DESC']);
        return new Response($this->twig->render('admin/unifi/rules.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'rules' => $rules,
            'form' => $formData['values'],
            'notice' => null,
            'errors' => $errors,
            'editRule' => $rule,
        ]), Response::HTTP_BAD_REQUEST);
    }

    #[Route(path: '/rules/{id}/delete', name: 'admin_unifi_rules_delete', methods: ['POST'])]
    public function deleteRule(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $rule = $this->manualRuleRepository->find($id);
        if ($rule instanceof UnifiManualRule) {
            $this->entityManager->remove($rule);
            $this->entityManager->flush();
            $this->messageBus->dispatch(new UnifiSyncManualRulesMessage());
        }

        return new RedirectResponse('/admin/unifi/rules?notice=deleted');
    }

    #[Route(path: '/auto-preview', name: 'admin_unifi_auto_preview', methods: ['GET'])]
    public function autoPreview(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $preview = $this->syncService->preview();
        $latestAudit = $this->auditLogRepository->findLast();

        return new Response($this->twig->render('admin/unifi/auto_preview.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'preview' => $preview,
            'latestAudit' => $latestAudit,
        ]));
    }

    #[Route(path: '/auto-preview/sync', name: 'admin_unifi_auto_preview_sync', methods: ['POST'])]
    public function syncPreview(Request $request): Response
    {
        $this->requireAdmin($request);
        $settings = $this->settingsRepository->getSettings();
        if (!$settings->isEnabled()) {
            return new Response('UniFi module disabled.', Response::HTTP_BAD_REQUEST);
        }
        $dryRun = $request->request->get('dry_run') === '1';
        $result = $this->syncService->sync(null, $dryRun);

        $query = $dryRun ? 'dry_run=1' : 'synced=1';
        return new RedirectResponse('/admin/unifi/auto-preview?' . $query . '&request_id=' . $result['request_id']);
    }

    #[Route(path: '/audit', name: 'admin_unifi_audit', methods: ['GET'])]
    public function audit(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $logs = $this->auditLogRepository->findLatest(200);

        return new Response($this->twig->render('admin/unifi/audit.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'logs' => $logs,
        ]));
    }

    #[Route(path: '/docs', name: 'admin_unifi_docs', methods: ['GET'])]
    public function docs(Request $request): Response
    {
        $admin = $this->requireAdmin($request);

        return new Response($this->twig->render('admin/unifi/docs.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
        ]));
    }

    private function renderConnectionResult(User $admin, $settings, array $resultCodes): Response
    {
        $nodes = $this->agentRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/unifi/connection.html.twig', [
            'activeNav' => 'unifi',
            'admin' => $admin,
            'settings' => $settings,
            'nodes' => $nodes,
            'notice' => null,
            'errors' => [],
            'connectionResult' => $resultCodes,
        ]));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function parseNodeTargets(array $input): array
    {
        $targets = [];
        foreach ($input as $nodeId => $value) {
            if (!is_string($nodeId)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $targets[$nodeId] = $value;
        }

        return $targets;
    }

    /**
     * @return int[]
     */
    private function parsePorts(string $value): array
    {
        $ports = [];
        foreach (preg_split('/[\s,]+/', $value) as $part) {
            $part = trim($part);
            if ($part === '' || !is_numeric($part)) {
                continue;
            }
            $port = (int) $part;
            if ($port > 0 && $port <= 65535) {
                $ports[] = $port;
            }
        }

        return array_values(array_unique($ports));
    }

    /**
     * @return array<int, array{start: int, end: int}>
     */
    private function parseRanges(string $value): array
    {
        $ranges = [];
        foreach (preg_split('/[\n,]+/', $value) as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, '-')) {
                continue;
            }
            [$start, $end] = array_map('trim', explode('-', $part, 2));
            if (!is_numeric($start) || !is_numeric($end)) {
                continue;
            }
            $startPort = (int) $start;
            $endPort = (int) $end;
            if ($startPort > 0 && $endPort >= $startPort && $endPort <= 65535) {
                $ranges[] = ['start' => $startPort, 'end' => $endPort];
            }
        }

        return $ranges;
    }

    /**
     * @return string[]
     */
    private function parseTags(string $value): array
    {
        $tags = [];
        foreach (preg_split('/[\s,]+/', $value) as $tag) {
            $tag = trim($tag);
            if ($tag === '') {
                continue;
            }
            $tags[] = $tag;
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param array<int, mixed> $protocols
     * @return string[]
     */
    private function normalizeProtocols(array $protocols): array
    {
        $normalized = [];
        foreach ($protocols as $protocol) {
            if (!is_string($protocol)) {
                continue;
            }
            $protocol = strtolower(trim($protocol));
            if ($protocol === '') {
                continue;
            }
            $normalized[] = $protocol;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array{name: string, protocol: string, port: int, target_ip: string, target_port: int, enabled: bool, description: ?string, errors: string[], values: array<string, mixed>}
     */
    private function normalizeRuleForm(Request $request): array
    {
        $errors = [];
        $name = trim((string) $request->request->get('name', ''));
        $protocol = strtolower(trim((string) $request->request->get('protocol', 'tcp')));
        $portValue = (string) $request->request->get('port', '');
        $targetIp = trim((string) $request->request->get('target_ip', ''));
        $targetPortValue = (string) $request->request->get('target_port', '');
        $enabled = $request->request->get('enabled') === '1';
        $description = trim((string) $request->request->get('description', ''));

        if ($name === '') {
            $errors[] = 'Name darf nicht leer sein.';
        }

        if (!in_array($protocol, ['tcp', 'udp'], true)) {
            $errors[] = 'Ungültiges Protokoll.';
        }

        if (!is_numeric($portValue) || (int) $portValue <= 0 || (int) $portValue > 65535) {
            $errors[] = 'Ungültiger Port.';
        }

        if ($targetIp === '') {
            $errors[] = 'Target IP ist erforderlich.';
        }

        if (!is_numeric($targetPortValue) || (int) $targetPortValue <= 0 || (int) $targetPortValue > 65535) {
            $errors[] = 'Ungültiger Ziel-Port.';
        }

        $port = (int) $portValue;
        $targetPort = (int) $targetPortValue;

        return [
            'name' => $name,
            'protocol' => $protocol,
            'port' => $port,
            'target_ip' => $targetIp,
            'target_port' => $targetPort,
            'enabled' => $enabled,
            'description' => $description === '' ? null : $description,
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'protocol' => $protocol,
                'port' => $portValue,
                'target_ip' => $targetIp,
                'target_port' => $targetPortValue,
                'enabled' => $enabled,
                'description' => $description,
            ],
        ];
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new \RuntimeException('Forbidden.');
        }

        return $actor;
    }
}
