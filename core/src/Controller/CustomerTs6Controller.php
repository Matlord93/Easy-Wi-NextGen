<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Ts6Instance;
use App\Entity\TsVirtualServer;
use App\Entity\User;
use App\Entity\Job;
use App\Enum\ModuleKey;
use App\Enum\UserType;
use App\Repository\Ts6InstanceRepository;
use App\Repository\TsVirtualServerRepository;
use App\Service\AuditLogger;
use App\Service\ModuleRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/ts6')]
final class CustomerTs6Controller
{
    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
        private readonly Ts6InstanceRepository $ts6InstanceRepository,
        private readonly TsVirtualServerRepository $tsVirtualServerRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
        private readonly string $projectDir,
    ) {
    }

    #[Route(path: '', name: 'customer_ts6_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        if (!$this->moduleRegistry->isEnabled(ModuleKey::Ts6->value)) {
            throw new NotFoundHttpException('TS6 module disabled.');
        }

        $instances = $this->ts6InstanceRepository->findBy(['customer' => $customer], ['updatedAt' => 'DESC']);
        $virtualServers = $this->tsVirtualServerRepository->findBy(['customer' => $customer], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('customer/ts6/index.html.twig', [
            'activeNav' => 'ts6',
            'instances' => $this->normalizeInstances($instances),
            'virtual_servers' => $this->normalizeVirtualServers($virtualServers),
            'virtual_servers_enabled' => $this->moduleRegistry->isEnabled(ModuleKey::TsVirtual->value),
            'commands' => $this->loadServerQueryDocs($this->projectDir . '/ts6/serverquerydocs'),
            'notice' => $this->resolveNoticeKey((string) $request->query->get('notice', '')),
        ]));
    }

    #[Route(path: '/{id}/action', name: 'customer_ts6_action', methods: ['POST'])]
    public function action(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);

        if (!$this->moduleRegistry->isEnabled(ModuleKey::Ts6->value)) {
            throw new NotFoundHttpException('TS6 module disabled.');
        }

        $instance = $this->ts6InstanceRepository->findOneBy(['id' => $id, 'customer' => $customer]);
        if ($instance === null) {
            throw new NotFoundHttpException('TS6 instance not found.');
        }

        $action = strtolower(trim((string) $request->request->get('action', '')));
        $jobType = $this->resolveJobType($action);
        $payload = $this->resolveActionPayload($action, $request);

        $job = $this->queueTs6Job($jobType, $instance, $payload);

        $this->auditLogger->log($customer, sprintf('ts6.instance_%s', $action), [
            'ts6_instance_id' => $instance->getId(),
            'customer_id' => $customer->getId(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return $this->redirectToIndex('customer_ts6_action_queued');
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function resolveJobType(string $action): string
    {
        return match ($action) {
            'start' => 'ts6.instance.start',
            'stop' => 'ts6.instance.stop',
            'restart' => 'ts6.instance.restart',
            'update' => 'ts6.instance.update',
            'backup' => 'ts6.instance.backup',
            'restore' => 'ts6.instance.restore',
            default => throw new BadRequestHttpException('Unsupported action.'),
        };
    }

    /**
     * @return array<string, string>
     */
    private function resolveActionPayload(string $action, Request $request): array
    {
        if ($action === 'backup') {
            $backupPath = trim((string) $request->request->get('backup_path', ''));
            return $backupPath === '' ? [] : ['backup_path' => $backupPath];
        }

        if ($action === 'restore') {
            $restorePath = trim((string) $request->request->get('restore_path', ''));
            if ($restorePath === '') {
                throw new BadRequestHttpException('Restore path required.');
            }

            return ['restore_path' => $restorePath];
        }

        return [];
    }

    private function redirectToIndex(?string $notice): Response
    {
        $params = [];
        if ($notice !== null) {
            $params['notice'] = $notice;
        }

        $query = $params === [] ? '' : ('?' . http_build_query($params));

        return new Response('', Response::HTTP_FOUND, ['Location' => '/ts6' . $query]);
    }

    private function resolveNoticeKey(string $notice): ?string
    {
        return match ($notice) {
            'customer_ts6_action_queued' => $notice,
            default => null,
        };
    }

    /**
     * @param Ts6Instance[] $instances
     * @return array<int, array<string, mixed>>
     */
    private function normalizeInstances(array $instances): array
    {
        return array_map(static function (Ts6Instance $instance): array {
            $node = $instance->getNode();
            $nodeLabel = $node->getName() ?: $node->getId();

            return [
                'id' => $instance->getId(),
                'name' => $instance->getName(),
                'status' => $instance->getStatus()->value,
                'status_label' => $instance->getStatus()->name,
                'node' => $nodeLabel,
                'updated_at' => $instance->getUpdatedAt(),
            ];
        }, $instances);
    }

    /**
     * @param TsVirtualServer[] $servers
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVirtualServers(array $servers): array
    {
        return array_map(static function (TsVirtualServer $server): array {
            return [
                'id' => $server->getId(),
                'name' => $server->getName(),
                'slots' => $server->getSlots(),
                'status' => $server->getStatus()->value,
                'status_label' => $server->getStatus()->name,
                'instance' => $server->getInstance()->getName(),
                'updated_at' => $server->getUpdatedAt(),
            ];
        }, $servers);
    }

    /**
     * @param array<string, string> $extraPayload
     */
    private function queueTs6Job(string $type, Ts6Instance $instance, array $extraPayload): Job
    {
        $payload = array_merge([
            'ts6_instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'service_name' => sprintf('ts6-%d', $instance->getId() ?? 0),
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    /**
     * @return array<int, array{command: string, usage: string, description: string}>
     */
    private function loadServerQueryDocs(string $docsPath): array
    {
        if (!is_dir($docsPath)) {
            return [];
        }

        $commands = [];
        $files = glob($docsPath . '/*.txt') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $command = basename($file, '.txt');
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $commands[] = [
                'command' => $command,
                'usage' => $this->extractSection($contents, 'Usage'),
                'description' => $this->extractDescription($contents),
            ];
        }

        return $commands;
    }

    private function extractSection(string $contents, string $section): string
    {
        $pattern = sprintf('/%s:\s*(.+?)(?:\n\n|\n[A-Z][A-Za-z ]+?:|$)/s', preg_quote($section, '/'));
        if (!preg_match($pattern, $contents, $matches)) {
            return '';
        }

        $lines = preg_split('/\r?\n/', trim($matches[1]));
        if ($lines === false) {
            return trim($matches[1]);
        }

        return trim(implode(' ', array_map('trim', $lines)));
    }

    private function extractDescription(string $contents): string
    {
        $description = $this->extractSection($contents, 'Description');
        if ($description === '') {
            return '';
        }

        $lines = preg_split('/\r?\n/', $description);
        if ($lines === false) {
            return $description;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $description;
    }
}
