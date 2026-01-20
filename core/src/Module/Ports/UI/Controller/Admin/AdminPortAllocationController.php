<?php

declare(strict_types=1);

namespace App\Module\Ports\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Ports\Infrastructure\Repository\PortAllocationRepository;
use App\Module\Ports\Infrastructure\Repository\PortPoolRepository;
use App\Repository\InstanceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/instances')]
final class AdminPortAllocationController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly PortAllocationRepository $portAllocationRepository,
        private readonly PortPoolRepository $portPoolRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/{id}/ports', name: 'admin_instances_ports', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $instance = $this->instanceRepository->find($id);
        if (!$instance instanceof Instance) {
            return new Response('Instance not found.', Response::HTTP_NOT_FOUND);
        }

        $allocations = $this->portAllocationRepository->findByInstance($instance);
        $pools = $this->portPoolRepository->findEnabledByNode($instance->getNode());
        $allocCounts = $this->portAllocationRepository->countAllocationsByPoolTag($instance->getNode());
        $allocMap = [];
        foreach ($allocCounts as $row) {
            $allocMap[$row['pool_tag']] = $row['allocated'];
        }

        $poolStats = [];
        foreach ($pools as $pool) {
            $total = $pool->getEndPort() - $pool->getStartPort() + 1;
            $allocated = $allocMap[$pool->getTag()] ?? 0;
            $poolStats[] = [
                'tag' => $pool->getTag(),
                'name' => $pool->getName(),
                'total' => $total,
                'allocated' => $allocated,
                'free' => max(0, $total - $allocated),
            ];
        }

        return new Response($this->twig->render('admin/instances/ports.html.twig', [
            'instance' => $instance,
            'allocations' => $allocations,
            'poolStats' => $poolStats,
            'activeNav' => 'game-instances',
        ]));
    }
}
