<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PortBlock;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\PortBlockRepository;
use App\Repository\PortPoolRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\PortLeaseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/port-blocks')]
final class AdminPortBlockController
{
    public function __construct(
        private readonly PortBlockRepository $portBlockRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly UserRepository $userRepository,
        private readonly PortLeaseManager $portLeaseManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_port_blocks', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return $this->renderPage();
    }

    #[Route(path: '', name: 'admin_port_blocks_create_page', methods: ['POST'])]
    public function create(Request $request): Response
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

        if ($poolId <= 0 || $customerId <= 0) {
            if ($poolId <= 0 && $customerId <= 0) {
                return $this->renderPage('Please select a pool and customer.');
            }

            if ($poolId <= 0) {
                return $this->renderPage('Please select a pool.');
            }

            return $this->renderPage('Please select a customer.');
        }

        if ($size <= 0) {
            return $this->renderPage('Please provide a valid block size.');
        }

        $pool = $this->portPoolRepository->find($poolId);
        if ($pool === null) {
            return $this->renderPage('Port pool not found.');
        }

        $customer = $this->userRepository->find($customerId);
        if ($customer === null || $customer->getType() !== UserType::Customer) {
            return $this->renderPage('Customer not found.');
        }

        try {
            if ($startPortValue !== null && $startPortValue !== '' && $endPortValue !== null && $endPortValue !== '') {
                if (!is_numeric($startPortValue) || !is_numeric($endPortValue)) {
                    return $this->renderPage('Please provide a valid port range.');
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

        $notice = count($blocks) > 1 ? 'Port blocks allocated.' : 'Port block allocated.';

        return $this->renderPage(null, $notice);
    }

    private function renderPage(?string $error = null, ?string $notice = null): Response
    {
        $blocks = $this->portBlockRepository->findBy([], ['createdAt' => 'DESC']);

        return new Response($this->twig->render('admin/port-blocks/index.html.twig', [
            'portBlocks' => array_map(fn (PortBlock $block) => $this->normalizeBlock($block), $blocks),
            'portPools' => $this->portPoolRepository->findBy([], ['name' => 'ASC']),
            'customers' => $this->userRepository->findCustomers(),
            'error' => $error,
            'notice' => $notice,
            'activeNav' => 'port-blocks',
        ]));
    }

    private function normalizeBlock(PortBlock $block): array
    {
        return [
            'id' => $block->getId(),
            'pool' => [
                'id' => $block->getPool()->getId(),
                'name' => $block->getPool()->getName(),
                'node' => $block->getPool()->getNode()->getName() ?? $block->getPool()->getNode()->getId(),
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
