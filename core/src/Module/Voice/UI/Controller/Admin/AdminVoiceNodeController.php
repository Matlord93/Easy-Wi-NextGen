<?php

declare(strict_types=1);

namespace App\Module\Voice\UI\Controller\Admin;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\VoiceNode;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Repository\JobRepository;
use App\Repository\VoiceInstanceRepository;
use App\Repository\VoiceNodeRepository;
use App\Repository\VoiceRateLimitStateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route('/admin/voice/nodes')]
final class AdminVoiceNodeController
{
    public function __construct(
        private readonly VoiceNodeRepository $repository,
        private readonly VoiceRateLimitStateRepository $rateLimitRepository,
        private readonly VoiceInstanceRepository $voiceInstanceRepository,
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
        private readonly Environment $twig,
    ) {
    }

    #[Route('', name: 'admin_voice_nodes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden', 403);
        }

        $provider = trim((string) $request->query->get('provider', ''));
        $enabledRaw = trim((string) $request->query->get('enabled', ''));
        $enabled = $enabledRaw === '' ? null : in_array(strtolower($enabledRaw), ['1', 'true', 'yes'], true);
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = max(1, min(200, (int) $request->query->get('per_page', 50)));

        $pagination = $this->repository->findPaginated($page, $perPage, $provider !== '' ? $provider : null, $enabled);

        $nodeIds = array_values(array_filter(array_map(static fn (VoiceNode $node): ?int => $node->getId(), $pagination['items']), static fn (?int $id): bool => is_int($id)));
        $latestByNode = $this->voiceInstanceRepository->findLatestByNodeIds($nodeIds);

        $rows = array_map(function (VoiceNode $node) use ($latestByNode): array {
            $state = $this->rateLimitRepository->findOneByNodeAndProvider($node, $node->getProviderType());
            $latest = $latestByNode[$node->getId()] ?? null;

            return [
                'node' => $node,
                'state' => $state,
                'latest' => $latest,
            ];
        }, $pagination['items']);

        return new Response($this->twig->render('admin/voice/nodes.html.twig', [
            'activeNav' => 'voice',
            'rows' => $rows,
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total' => $pagination['total'],
            'filter_provider' => $provider,
            'filter_enabled' => $enabledRaw,
        ]));
    }

    #[Route('/api/v1/{id}/probe', name: 'admin_voice_nodes_probe_v1', methods: ['POST'])]
    public function probeNow(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return $this->responseEnvelopeFactory->error($request, 'Unauthorized.', 'unauthorized', 401);
        }

        $node = $this->repository->find($id);
        if (!$node instanceof VoiceNode) {
            return $this->responseEnvelopeFactory->error($request, 'Node not found.', 'voice_node_not_found', 404);
        }

        $active = $this->jobRepository->findActiveByTypeAndPayloadField('voice.probe', 'node_id', (string) $node->getId());
        if ($active !== null) {
            return $this->responseEnvelopeFactory->success(
                $request,
                $active->getId(),
                'Probe already queued for this node.',
                202,
                ['status' => 'running', 'error_code' => 'voice_probe_in_progress', 'retry_after' => 10],
            );
        }

        $instance = $this->voiceInstanceRepository->findOneLatestByNodeId($id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'No voice instance on this node.', 'voice_instance_not_found', 404);
        }

        $job = new Job('voice.probe', [
            'voice_instance_id' => (string) $instance->getId(),
            'provider_type' => $node->getProviderType(),
            'external_id' => $instance->getExternalId(),
            'node_id' => (string) $node->getId(),
        ]);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Node probe queued.', 202);
    }

    #[Route('', name: 'admin_voice_nodes_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden', 403);
        }

        $name = trim((string) $request->request->get('name', ''));
        $providerType = trim((string) $request->request->get('provider_type', ''));
        $host = trim((string) $request->request->get('host', ''));
        $queryPort = (int) $request->request->get('query_port', 0);
        $username = trim((string) $request->request->get('query_user', ''));
        $secret = trim((string) $request->request->get('query_secret', ''));

        if ($name === '' || !in_array($providerType, ['ts3', 'ts6'], true) || $host === '' || $queryPort <= 0) {
            return new Response('Invalid input.', 400);
        }

        $node = new VoiceNode($name, $providerType, $host, $queryPort);
        if ($username !== '' || $secret !== '') {
            $node->setCredentialsEncrypted([
                'user' => $username,
                'secret' => $secret !== '' ? $this->encryptionService->encrypt($secret) : null,
            ]);
        }

        $this->entityManager->persist($node);
        $this->entityManager->flush();

        return new Response('', 303, ['Location' => '/admin/voice/nodes']);
    }

    #[Route('/{id}/toggle', name: 'admin_voice_nodes_toggle', methods: ['POST'])]
    public function toggle(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden', 403);
        }

        $node = $this->repository->find($id);
        if (!$node instanceof VoiceNode) {
            return new Response('Not found', 404);
        }

        $node->setEnabled(!$node->isEnabled());
        $this->entityManager->flush();

        return new Response('', 303, ['Location' => '/admin/voice/nodes']);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
