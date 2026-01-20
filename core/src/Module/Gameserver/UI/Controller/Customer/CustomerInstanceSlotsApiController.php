<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Gameserver\Application\InstanceSlotService;
use App\Repository\InstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CustomerInstanceSlotsApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceSlotService $instanceSlotService,
        private readonly AppSettingsService $appSettingsService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/instances/{id}/slots', name: 'customer_instance_slots_update', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/slots', name: 'customer_instance_slots_update_v1', methods: ['POST'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            return new JsonResponse(['error' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $slotsValue = $payload['slots'] ?? null;
        if ($slotsValue === null || !is_numeric($slotsValue)) {
            return new JsonResponse(['error' => 'slots is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($instance->isLockSlots()) {
            return new JsonResponse(['error' => 'Slots are locked for this instance.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $requestedSlots = (int) $slotsValue;
        if ($requestedSlots <= 0) {
            return new JsonResponse(['error' => 'slots must be positive.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $minSlots = $this->appSettingsService->getGameserverMinSlots();
        $maxSlots = $this->appSettingsService->getGameserverMaxSlots();
        if ($requestedSlots < $minSlots) {
            return new JsonResponse(['error' => 'slots cannot be lower than min_slots.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($requestedSlots > $maxSlots) {
            return new JsonResponse(['error' => 'slots cannot exceed global max_slots.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($requestedSlots > $instance->getMaxSlots()) {
            return new JsonResponse(['error' => 'slots cannot exceed max_slots.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $current = $this->instanceSlotService->enforceSlots($instance, $requestedSlots);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return new JsonResponse([
            'current_slots' => $current,
            'max_slots' => $instance->getMaxSlots(),
            'lock_slots' => $instance->isLockSlots(),
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
        if ($instance === null || $instance->getCustomer()->getId() !== $customer->getId()) {
            throw new NotFoundHttpException('Instance not found.');
        }

        return $instance;
    }
}
