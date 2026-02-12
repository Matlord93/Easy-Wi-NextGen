<?php

declare(strict_types=1);

namespace App\Module\Voice\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Voice\Application\Provider\VoiceProviderRegistry;
use App\Module\Voice\Application\VoiceProbeGuard;
use App\Repository\JobRepository;
use App\Repository\VoiceInstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/customer/voice')]
final class CustomerVoiceApiController
{
    public function __construct(
        private readonly VoiceInstanceRepository $repository,
        private readonly JobRepository $jobRepository,
        private readonly VoiceProviderRegistry $providers,
        private readonly VoiceProbeGuard $probeGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
    ) {
    }

    #[Route('', name: 'customer_voice_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->repository->findByCustomer($customer, 200);

        return new JsonResponse(['instances' => array_map(fn ($instance) => $this->normalize($instance), $instances)]);
    }

    #[Route('/{id}/probe', name: 'customer_voice_probe_v1', methods: ['POST'])]
    public function probe(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $checkedAt = $instance->getCheckedAt();
        $isFresh = $checkedAt !== null && $checkedAt > new \DateTimeImmutable('-45 seconds');
        if ($isFresh) {
            return new JsonResponse([
                'job_id' => null,
                'status' => 'succeeded',
                'message' => 'Using cached status.',
                'request_id' => $this->resolveRequestId($request),
                'details' => ['cached' => true, 'instance' => $this->normalize($instance)],
            ], 200);
        }

        $guard = $this->probeGuard->allow($instance);
        if (!$guard['allowed']) {
            return $this->responseEnvelopeFactory->error(
                $request,
                (string) $guard['reason'],
                (string) $guard['error_code'],
                429,
                (int) $guard['retry_after'],
                ['job_id' => null, 'details' => ['stale' => true]],
            );
        }

        $lockHandle = $this->acquireInstanceLock('probe', (int) $instance->getId());
        if (!is_resource($lockHandle)) {
            return $this->responseEnvelopeFactory->error(
                $request,
                'Probe lock is active for this instance.',
                'voice_probe_in_progress',
                409,
                3,
                ['job_id' => null, 'details' => ['stale' => true]],
            );
        }

        try {
            $active = $this->findActiveProbeJob((string) $instance->getId());
            if ($active !== null) {
                return $this->responseEnvelopeFactory->success(
                    $request,
                    $active->getId(),
                    'Probe already queued.',
                    202,
                    ['status' => 'running', 'error_code' => 'voice_probe_in_progress', 'retry_after' => 10, 'details' => ['stale' => true]],
                );
            }

            $job = new Job('voice.probe', [
                'voice_instance_id' => (string) $instance->getId(),
                'provider_type' => $instance->getNode()->getProviderType(),
                'external_id' => $instance->getExternalId(),
                'node_id' => (string) $instance->getNode()->getId(),
            ]);
            $this->entityManager->persist($job);
            $this->entityManager->flush();
        } finally {
            $this->releaseInstanceLock($lockHandle);
        }

        return $this->responseEnvelopeFactory->success(
            $request,
            $job->getId(),
            'Probe queued. Returning stale status.',
            202,
            ['status' => 'pending', 'details' => ['stale' => true, 'instance' => $this->normalize($instance)]],
        );
    }

    #[Route('/{id}/actions/{action}', name: 'customer_voice_action_v1', methods: ['POST'])]
    public function action(Request $request, int $id, string $action): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerVoiceInstance($customer, $id);
        if ($instance === null) {
            return $this->responseEnvelopeFactory->error($request, 'Instance not found.', 'voice_instance_not_found', 404);
        }

        $action = strtolower(trim($action));
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            return $this->responseEnvelopeFactory->error($request, 'Invalid action.', 'voice_action_invalid', 400);
        }

        $lockHandle = $this->acquireInstanceLock('action', (int) $instance->getId());
        if (!is_resource($lockHandle)) {
            return $this->responseEnvelopeFactory->error($request, 'Action lock is active for this instance.', 'voice_action_in_progress', 409, 3);
        }

        try {
            $activeAction = $this->findActiveActionJob((string) $instance->getId());
            if ($activeAction !== null) {
                if ($activeAction->getType() === 'voice.action.' . $action) {
                    return $this->responseEnvelopeFactory->success(
                        $request,
                        $activeAction->getId(),
                        'Action already queued.',
                        202,
                        ['status' => 'running', 'error_code' => 'voice_action_in_progress', 'retry_after' => 10],
                    );
                }

                return $this->responseEnvelopeFactory->error(
                    $request,
                    'Another action is already in progress.',
                    'voice_action_in_progress',
                    409,
                    10,
                    ['job_id' => $activeAction->getId()],
                );
            }

            try {
                $adapter = $this->providers->forType($instance->getNode()->getProviderType());
            } catch (\RuntimeException) {
                return $this->responseEnvelopeFactory->error($request, 'Unsupported voice provider.', 'voice_provider_unsupported', 400);
            }

            $validation = $adapter->performAction($instance, $action);
            if (!($validation['accepted'] ?? false)) {
                return $this->responseEnvelopeFactory->error($request, (string) ($validation['reason'] ?? 'Action rejected.'), (string) ($validation['error_code'] ?? 'voice_action_rejected'), 400);
            }

            $job = new Job('voice.action.' . $action, [
                'voice_instance_id' => (string) $instance->getId(),
                'provider_type' => $instance->getNode()->getProviderType(),
                'external_id' => $instance->getExternalId(),
                'node_id' => (string) $instance->getNode()->getId(),
            ]);
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            return $this->responseEnvelopeFactory->success($request, $job->getId(), 'Action queued.');
        } finally {
            $this->releaseInstanceLock($lockHandle);
        }
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function normalize($instance): array
    {
        try {
            $adapter = $this->providers->forType($instance->getNode()->getProviderType());
            $connect = $adapter->getConnectInfo($instance);
        } catch (\RuntimeException) {
            $connect = ['host' => $instance->getNode()->getHost(), 'port' => null];
        }

        $activeProbe = $this->findActiveProbeJob((string) $instance->getId());
        $activeAction = $this->findActiveActionJob((string) $instance->getId());

        return [
            'id' => $instance->getId(),
            'name' => $instance->getName(),
            'provider_type' => $instance->getNode()->getProviderType(),
            'status' => $instance->getStatus(),
            'players_online' => $instance->getPlayersOnline(),
            'players_max' => $instance->getPlayersMax(),
            'reason' => $instance->getReason(),
            'error_code' => $instance->getErrorCode(),
            'checked_at' => $instance->getCheckedAt()?->format(DATE_RFC3339),
            'stale' => $instance->getCheckedAt() === null || $instance->getCheckedAt() < new \DateTimeImmutable('-45 seconds'),
            'probe_in_progress' => $activeProbe !== null,
            'action_in_progress' => $activeAction !== null,
            'active_job_id' => $activeAction?->getId() ?? $activeProbe?->getId(),
            'retry_after' => ($activeAction !== null || $activeProbe !== null) ? 10 : null,
            'connect' => $connect,
        ];
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = trim((string) ($request->headers->get('X-Request-ID') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $traceId = trim((string) ($request->attributes->get('request_id') ?? ''));

        return $traceId !== '' ? $traceId : 'req-' . bin2hex(random_bytes(6));
    }

    private function findCustomerVoiceInstance(User $customer, int $id): ?\App\Module\Core\Domain\Entity\VoiceInstance
    {
        $instance = $this->repository->find($id);
        if ($instance === null || $instance->getCustomer()->getId() !== $customer->getId()) {
            return null;
        }

        return $instance;
    }

    private function findActiveActionJob(string $voiceInstanceId): ?Job
    {
        foreach (['voice.action.start', 'voice.action.stop', 'voice.action.restart'] as $type) {
            $active = $this->jobRepository->findActiveByTypeAndPayloadField($type, 'voice_instance_id', $voiceInstanceId);
            if ($active !== null) {
                return $active;
            }
        }

        return null;
    }

    private function findActiveProbeJob(string $voiceInstanceId): ?Job
    {
        return $this->jobRepository->findActiveByTypeAndPayloadField('voice.probe', 'voice_instance_id', $voiceInstanceId);
    }

    /** @return resource|false */
    private function acquireInstanceLock(string $scope, int $instanceId)
    {
        $dir = sys_get_temp_dir() . '/easywi_voice_locks';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $path = sprintf('%s/%s_%d.lock', $dir, $scope, $instanceId);
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return false;
        }

        return $handle;
    }

    /** @param resource|false $handle */
    private function releaseInstanceLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
