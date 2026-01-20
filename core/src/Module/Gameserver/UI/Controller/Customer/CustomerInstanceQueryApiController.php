<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceQueryService;
use App\Module\Ports\Infrastructure\Repository\PortBlockRepository;
use App\Repository\InstanceRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerInstanceQueryApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly PortBlockRepository $portBlockRepository,
        private readonly InstanceQueryService $instanceQueryService,
    ) {
    }

    #[Route(path: '/api/customer/instances/{id}/query', name: 'customer_instance_query_api', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/query', name: 'customer_instance_query_api_v1', methods: ['GET'])]
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $portBlock = $this->portBlockRepository->findByInstance($instance);

        $snapshot = $this->instanceQueryService->getSnapshot($instance, $portBlock, true);

        return new JsonResponse([
            'query' => $snapshot,
        ]);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findCustomerInstance(User $customer, int $id): Instance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }

        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }
}
