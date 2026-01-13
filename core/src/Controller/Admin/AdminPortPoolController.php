<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PortPool;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\AgentRepository;
use App\Repository\PortPoolRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/port-pools')]
final class AdminPortPoolController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_port_pools', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return $this->renderPage();
    }

    #[Route(path: '', name: 'admin_port_pools_create_page', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $nodeId = (string) $request->request->get('node_id', '');
        $name = trim((string) $request->request->get('name', ''));
        $startValue = $request->request->get('start_port');
        $endValue = $request->request->get('end_port');

        if ($nodeId === '' || $name === '' || $startValue === null || $endValue === null) {
            return $this->renderPage('Please complete all required fields.');
        }

        if (!is_numeric($startValue) || !is_numeric($endValue)) {
            return $this->renderPage('Please provide a valid port range.');
        }

        $startPort = (int) $startValue;
        $endPort = (int) $endValue;
        if ($startPort <= 0 || $endPort <= 0 || $startPort > 65535 || $endPort > 65535 || $startPort > $endPort) {
            return $this->renderPage('Port range must be between 1 and 65535.');
        }

        $node = $this->agentRepository->find($nodeId);
        if ($node === null) {
            return $this->renderPage('Node not found.');
        }

        $pool = new PortPool($node, $name, $startPort, $endPort);
        $this->entityManager->persist($pool);

        $this->auditLogger->log($actor, 'port_pool.created', [
            'port_pool_id' => $pool->getId(),
            'node_id' => $node->getId(),
            'name' => $pool->getName(),
            'start_port' => $pool->getStartPort(),
            'end_port' => $pool->getEndPort(),
        ]);

        $this->entityManager->flush();

        return $this->renderPage(null, 'Port pool created.');
    }

    private function renderPage(?string $error = null, ?string $notice = null): Response
    {
        $pools = $this->portPoolRepository->findBy([], ['createdAt' => 'DESC']);
        $nodes = $this->agentRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/port-pools/index.html.twig', [
            'portPools' => array_map(fn (PortPool $pool) => $this->normalizePool($pool), $pools),
            'nodes' => $nodes,
            'error' => $error,
            'notice' => $notice,
            'activeNav' => 'port-pools',
        ]));
    }

    private function normalizePool(PortPool $pool): array
    {
        return [
            'id' => $pool->getId(),
            'name' => $pool->getName(),
            'node' => [
                'id' => $pool->getNode()->getId(),
                'name' => $pool->getNode()->getName(),
            ],
            'start_port' => $pool->getStartPort(),
            'end_port' => $pool->getEndPort(),
            'created_at' => $pool->getCreatedAt(),
        ];
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
