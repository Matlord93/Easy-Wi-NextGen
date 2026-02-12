<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\WebspaceNode;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Repository\AgentRepository;
use App\Repository\WebspaceNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/admin/webspaces/nodes')]
final class AdminWebspaceNodeController
{
    public function __construct(
        private readonly WebspaceNodeRepository $repository,
        private readonly AgentRepository $agentRepository,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'admin_webspace_nodes_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $nodes = $this->repository->findBy([], ['id' => 'DESC'], 200);

        return new JsonResponse(['nodes' => array_map(static fn (WebspaceNode $n): array => [
            'id' => $n->getId(),
            'name' => $n->getName(),
            'host' => $n->getHost(),
            'enabled' => $n->isEnabled(),
            'webserver_type' => $n->getWebserverType(),
            'base_path' => $n->getBasePath(),
            'vhost_paths' => $n->getVhostPaths(),
            'php_fpm_mode' => $n->getPhpFpmMode(),
        ], $nodes)]);
    }

    #[Route('', name: 'admin_webspace_nodes_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $data = $request->toArray();
        $validation = $this->validateNodePayload($data);
        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        $node = new WebspaceNode((string) $data['name'], (string) $data['host'], (string) $data['base_path'], $validation['agent']);
        $this->hydrateNode($node, $data);
        $this->entityManager->persist($node);
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, 'node-'.$node->getId(), 'Webspace node created.', JsonResponse::HTTP_CREATED, [
            'status' => 'succeeded',
            'details' => ['node_id' => $node->getId()],
        ]);
    }

    #[Route('/{id}', name: 'admin_webspace_nodes_update_v1', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $node = $this->repository->find($id);
        if (!$node instanceof WebspaceNode) {
            return $this->responseEnvelopeFactory->error($request, 'Node not found.', 'webspace_node_invalid', 404);
        }

        $data = $request->toArray();
        $validation = $this->validateNodePayload($data, true);
        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        if (isset($validation['agent'])) {
            $node->setAgent($validation['agent']);
        }
        if (isset($data['name'])) {
            $node->setName((string) $data['name']);
        }
        if (isset($data['host'])) {
            $node->setHost((string) $data['host']);
        }
        if (isset($data['base_path'])) {
            $node->setBasePath((string) $data['base_path']);
        }
        $this->hydrateNode($node, $data);

        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, 'node-'.$node->getId(), 'Webspace node updated.', 200, ['status' => 'succeeded']);
    }

    #[Route('/{id}/toggle', name: 'admin_webspace_nodes_toggle_v1', methods: ['POST'])]
    public function toggle(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $node = $this->repository->find($id);
        if (!$node instanceof WebspaceNode) {
            return $this->responseEnvelopeFactory->error($request, 'Node not found.', 'webspace_node_invalid', 404);
        }

        $node->setEnabled(!$node->isEnabled());
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, 'node-'.$node->getId(), 'Node toggled.', 200, ['status' => 'succeeded', 'details' => ['enabled' => $node->isEnabled()]]);
    }

    #[Route('/{id}/test', name: 'admin_webspace_nodes_test_v1', methods: ['POST'])]
    public function test(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $node = $this->repository->find($id);
        if (!$node instanceof WebspaceNode) {
            return $this->responseEnvelopeFactory->error($request, 'Node not found.', 'webspace_node_invalid', 404);
        }

        $checks = [];
        $checks[] = ['base_path' => $node->getBasePath(), 'exists' => is_dir($node->getBasePath()), 'writable' => is_writable($node->getBasePath())];
        foreach ($node->getVhostPaths() as $kind => $path) {
            if (!is_string($path)) {
                continue;
            }
            $checks[] = [$kind => $path, 'exists' => is_dir($path) || is_file($path), 'writable' => is_writable(dirname($path))];
        }

        $commands = [];
        $type = $node->getWebserverType();
        if (in_array($type, ['nginx', 'both'], true)) {
            $commands[] = $this->runCommandCheck('nginx -t');
        }
        if (in_array($type, ['apache', 'both'], true)) {
            $commands[] = $this->runCommandCheck('apachectl configtest');
        }

        $phpMode = $node->getPhpFpmMode();
        $phpModeOk = $phpMode === null || in_array($phpMode, ['ondemand', 'dynamic', 'static'], true);

        $ok = $phpModeOk;
        foreach ($checks as $check) {
            $ok = $ok && (bool) ($check['exists'] ?? false);
        }
        foreach ($commands as $command) {
            $ok = $ok && (($command['exit_code'] ?? 1) === 0);
        }

        return new JsonResponse([
            'status' => $ok ? 'ok' : 'failed',
            'error_code' => $ok ? null : 'configtest_failed',
            'details' => [
                'path_checks' => $checks,
                'command_checks' => $commands,
                'php_fpm_mode' => ['value' => $phpMode, 'valid' => $phpModeOk],
            ],
        ], $ok ? 200 : 422);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{agent?:\App\Module\Core\Domain\Entity\Agent}|JsonResponse
     */
    private function validateNodePayload(array $data, bool $partial = false): array|JsonResponse
    {
        $required = ['name', 'host', 'base_path'];
        foreach ($required as $field) {
            if (!$partial && trim((string) ($data[$field] ?? '')) === '') {
                return new JsonResponse(['error' => 'validation_failed', 'field' => $field], 400);
            }
        }

        if (isset($data['webserver_type']) && !in_array((string) $data['webserver_type'], ['nginx', 'apache', 'both'], true)) {
            return new JsonResponse(['error' => 'validation_failed', 'field' => 'webserver_type'], 400);
        }

        $agent = null;
        if (isset($data['agent_id'])) {
            $agent = $this->agentRepository->find((string) $data['agent_id']);
            if ($agent === null) {
                return new JsonResponse(['error' => 'agent_offline_or_unknown'], 404);
            }
        } elseif (!$partial) {
            return new JsonResponse(['error' => 'validation_failed', 'field' => 'agent_id'], 400);
        }

        return $agent === null ? [] : ['agent' => $agent];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function hydrateNode(WebspaceNode $node, array $data): void
    {
        if (isset($data['webserver_type'])) {
            $node->setWebserverType((string) $data['webserver_type']);
        }
        if (isset($data['vhost_paths']) && is_array($data['vhost_paths'])) {
            $node->setVhostPaths($data['vhost_paths']);
        }
        if (array_key_exists('php_fpm_mode', $data)) {
            $mode = trim((string) ($data['php_fpm_mode'] ?? ''));
            $node->setPhpFpmMode($mode === '' ? null : $mode);
        }
        if (isset($data['default_templates']) && is_array($data['default_templates'])) {
            $node->setDefaultTemplates($data['default_templates']);
        }
        if (isset($data['tls_defaults']) && is_array($data['tls_defaults'])) {
            $node->setTlsDefaults($data['tls_defaults']);
        }
        if (array_key_exists('enabled', $data)) {
            $node->setEnabled((bool) $data['enabled']);
        }
    }

    /** @return array{command:string,exit_code:int,output:string} */
    private function runCommandCheck(string $command): array
    {
        $output = [];
        $exit = 0;
        @exec($command . ' 2>&1', $output, $exit);

        return [
            'command' => $command,
            'exit_code' => $exit,
            'output' => substr(implode("\n", $output), 0, 500),
        ];
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
