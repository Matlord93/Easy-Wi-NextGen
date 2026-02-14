<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceSlotService;
use App\Repository\InstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CustomerInstanceSlotsApiController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly InstanceSlotService $instanceSlotService,
        private readonly AppSettingsService $appSettingsService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/instances/{id}/slots', name: 'customer_instance_slots_update', methods: ['POST', 'PATCH'])]
    #[Route(path: '/api/v1/customer/instances/{id}/slots', name: 'customer_instance_slots_update_v1', methods: ['POST', 'PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
        } catch (HttpExceptionInterface $exception) {
            return $this->apiError(
                $request,
                $exception instanceof AccessDeniedHttpException ? 'FORBIDDEN' : ($exception instanceof UnauthorizedHttpException ? 'UNAUTHORIZED' : 'NOT_FOUND'),
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
                $exception->getStatusCode(),
            );
        }

        try {
            $payload = $request->toArray();
        } catch (\JsonException) {
            return $this->apiError($request, 'INVALID_INPUT', 'Invalid JSON payload.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $slotsValue = $payload['slots'] ?? null;
        if ($slotsValue === null || !is_numeric($slotsValue)) {
            return $this->apiError($request, 'INVALID_INPUT', 'slots is required.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($instance->isLockSlots()) {
            return $this->apiError($request, 'FORBIDDEN', 'Slots are locked for this instance.', JsonResponse::HTTP_FORBIDDEN);
        }

        $requestedSlots = (int) $slotsValue;
        if ($requestedSlots <= 0) {
            return $this->apiError($request, 'INVALID_INPUT', 'slots must be positive.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $minSlots = $this->appSettingsService->getGameserverMinSlots();
        $maxSlots = $this->appSettingsService->getGameserverMaxSlots();
        if ($requestedSlots < $minSlots) {
            return $this->apiError($request, 'INVALID_INPUT', 'slots cannot be lower than min_slots.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($requestedSlots > $maxSlots) {
            return $this->apiError($request, 'INVALID_INPUT', 'slots cannot exceed global max_slots.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($requestedSlots > $instance->getMaxSlots()) {
            return $this->apiError($request, 'INVALID_INPUT', 'slots cannot exceed max_slots.', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $current = $this->instanceSlotService->enforceSlots($instance, $requestedSlots);
        $this->entityManager->persist($instance);
        $this->entityManager->flush();

        return $this->apiOk($request, [
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
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    private function apiOk(Request $request, array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'data' => $data,
            'request_id' => $this->resolveRequestId($request),
        ], $status);
    }

    private function apiError(Request $request, string $errorCode, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'request_id' => $this->resolveRequestId($request),
        ], $status);
    }

    private function resolveRequestId(Request $request): string
    {
        return trim((string) ($request->headers->get('X-Request-ID') ?: $request->attributes->get('request_id') ?: ''));
    }
}
