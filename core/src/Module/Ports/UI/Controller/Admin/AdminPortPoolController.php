<?php

declare(strict_types=1);

namespace App\Module\Ports\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Ports\Application\PortLeaseManager;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Repository\AgentRepository;
use App\Repository\UserRepository;
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
        private readonly PortBlockRepository $portBlockRepository,
        private readonly UserRepository $userRepository,
        private readonly PortLeaseManager $portLeaseManager,
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
        $tag = trim((string) $request->request->get('tag', ''));
        $startValue = $request->request->get('start_port');
        $endValue = $request->request->get('end_port');
        $enabled = $request->request->get('enabled') === '1';
        $allocationMode = (string) $request->request->get('allocation_mode', 'next');

        if ($nodeId === '' || $name === '' || $tag === '' || $startValue === null || $endValue === null) {
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

        $allocationStep = $allocationMode === 'gap10' ? 10 : 1;
        $pool = new PortPool($node, $name, $tag, $startPort, $endPort, $enabled, $allocationStep);
        $this->entityManager->persist($pool);

        $this->auditLogger->log($actor, 'port_pool.created', [
            'port_pool_id' => $pool->getId(),
            'node_id' => $node->getId(),
            'name' => $pool->getName(),
            'tag' => $pool->getTag(),
            'start_port' => $pool->getStartPort(),
            'end_port' => $pool->getEndPort(),
            'enabled' => $pool->isEnabled(),
            'allocation_step' => $pool->getAllocationStep(),
        ]);

        $this->entityManager->flush();

        return $this->renderPage(null, 'Server port pool created.');
    }

    #[Route(path: '/blocks', name: 'admin_port_pools_assign_block', methods: ['POST'])]
    public function assignBlock(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $poolId = (int) $request->request->get('pool_id', 0);
        $customerId = (int) $request->request->get('customer_id', 0);
        $size = (int) $request->request->get('size', 0);
        $startPortValue = $request->request->get('start_port');
        $endPortValue = $request->request->get('end_port');

        if ($poolId <= 0 || $customerId <= 0 || $size <= 0) {
            return $this->renderPage('Please select pool, customer and block size.');
        }

        $pool = $this->portPoolRepository->find($poolId);
        if (!$pool instanceof PortPool) {
            return $this->renderPage('Port pool not found.');
        }

        $customer = $this->userRepository->find($customerId);
        if (!$customer instanceof User || $customer->getType() !== UserType::Customer) {
            return $this->renderPage('Customer not found.');
        }

        try {
            if ($startPortValue !== null && $startPortValue !== '' && $endPortValue !== null && $endPortValue !== '') {
                if (!is_numeric($startPortValue) || !is_numeric($endPortValue)) {
                    return $this->renderPage('Please provide a valid custom range.');
                }

                $blocks = $this->portLeaseManager->allocateBlocksInRange(
                    $pool,
                    $customer,
                    (int) $startPortValue,
                    (int) $endPortValue,
                    $size,
                );
            } else {
                $blocks = [$this->portLeaseManager->allocateBlock($pool, $customer, $size)];
            }
        } catch (\RuntimeException | \InvalidArgumentException $exception) {
            return $this->renderPage($exception->getMessage());
        }

        foreach ($blocks as $block) {
            $this->entityManager->persist($block);
            $this->auditLogger->log($actor, 'port_block.created', [
                'port_block_id' => $block->getId(),
                'port_pool_id' => $pool->getId(),
                'customer_id' => $customer->getId(),
                'start_port' => $block->getStartPort(),
                'end_port' => $block->getEndPort(),
            ]);
        }
        $this->entityManager->flush();

        return $this->renderPage(null, count($blocks) > 1 ? 'Server port blocks allocated.' : 'Server port block allocated.');
    }

    private function renderPage(?string $error = null, ?string $notice = null): Response
    {
        $pools = $this->portPoolRepository->findBy([], ['createdAt' => 'DESC']);
        $nodes = $this->agentRepository->findBy([], ['name' => 'ASC']);
        $blocks = $this->portBlockRepository->findBy([], ['createdAt' => 'DESC']);

        return new Response($this->twig->render('admin/port-pools/index.html.twig', [
            'portPools' => array_map(fn (PortPool $pool) => $this->normalizePool($pool), $pools),
            'portBlocks' => array_map(fn (PortBlock $block) => $this->normalizeBlock($block), $blocks),
            'nodes' => $nodes,
            'customers' => $this->userRepository->findCustomers(),
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
            'tag' => $pool->getTag(),
            'node' => [
                'id' => $pool->getNode()->getId(),
                'name' => $pool->getNode()->getName(),
            ],
            'start_port' => $pool->getStartPort(),
            'end_port' => $pool->getEndPort(),
            'enabled' => $pool->isEnabled(),
            'allocation_step' => $pool->getAllocationStep(),
            'created_at' => $pool->getCreatedAt(),
        ];
    }

    private function normalizeBlock(PortBlock $block): array
    {
        return [
            'id' => $block->getId(),
            'pool' => [
                'id' => $block->getPool()->getId(),
                'name' => $block->getPool()->getName(),
            ],
            'customer' => [
                'id' => $block->getCustomer()->getId(),
                'email' => $block->getCustomer()->getEmail(),
            ],
            'instance_id' => $block->getInstance()?->getId(),
            'start_port' => $block->getStartPort(),
            'end_port' => $block->getEndPort(),
            'assigned_at' => $block->getCreatedAt(),
        ];
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
