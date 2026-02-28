<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandLimiterInterface;
use App\Module\Gameserver\Application\Console\ConsoleCommandRequest;
use App\Repository\InstanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route(path: '/instances')]
final class CustomerInstanceConsoleCommandController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
        private readonly ConsoleAgentGrpcClientInterface $grpcClient,
        private readonly ConsoleCommandLimiterInterface $consoleLimiter,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        #[Autowire('%kernel.secret%')]
        private readonly string $auditHmacKey,
    ) {
    }

    #[Route(path: '/{id}/console/command', name: 'customer_instance_console_command_v2', methods: ['POST'])]
    public function send(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }
        $isOwner = $instance->getCustomer()?->getId() === $actor->getId();
        $isAdmin = $actor->getType() === UserType::Admin;
        if (!$isOwner && !$isAdmin) {
            $this->audit($actor, 'command_blocked', $id, null, 'forbidden');
            return $this->responseEnvelopeFactory->error($request, 'Forbidden', 'FORBIDDEN', JsonResponse::HTTP_FORBIDDEN);
        }

        $body = json_decode((string) $request->getContent(), true);
        $command = $this->normalizeCommand((string) ($body['command'] ?? ''));
        if ($command === '' || strlen($command) > 1024) {
            $this->audit($actor, 'command_blocked', $id, $command, 'invalid_length');
            return $this->responseEnvelopeFactory->error($request, 'Invalid command length.', 'INVALID_INPUT', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->requiresCsrf($request) && !$this->isCsrfValid($request, $id, (string) ($body['csrf_token'] ?? ''))) {
            $this->audit($actor, 'command_blocked', $id, $command, 'csrf_invalid');
            return $this->responseEnvelopeFactory->error($request, 'Invalid CSRF token.', 'CSRF_INVALID', JsonResponse::HTTP_FORBIDDEN);
        }

        $limiterKey = sprintf('console:%d:%d', $actor->getId() ?? 0, $id);
        if (!$this->consoleLimiter->consume($limiterKey) || !$this->consoleLimiter->consume(sprintf('console_instance:%d', $id))) {
            $this->audit($actor, 'command_blocked', $id, $command, 'rate_limited');
            return $this->responseEnvelopeFactory->error($request, 'Rate limit exceeded.', 'RATE_LIMITED', JsonResponse::HTTP_TOO_MANY_REQUESTS);
        }

        $idempotency = trim((string) ($body['idempotency_key'] ?? ''));
        if ($idempotency === '') {
            $idempotency = bin2hex(random_bytes(16));
        }

        try {
            $result = $this->grpcClient->sendCommand(new ConsoleCommandRequest($id, $command, $idempotency, (int) floor(microtime(true) * 1000), (string) ($actor->getId() ?? '0')));
        } catch (\Throwable $e) {
            $this->audit($actor, 'command_failed', $id, $command, 'grpc_failed');
            $this->entityManager->flush();

            return $this->responseEnvelopeFactory->error($request, 'Command dispatch failed.', 'DISPATCH_FAILED', JsonResponse::HTTP_BAD_GATEWAY);
        }

        $this->audit($actor, 'command_sent', $id, $command, 'ok');
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'queued',
            'message' => 'Command sent.',
            'request_id' => (string) ($request->headers->get('X-Request-ID') ?? ''),
            'accepted' => true,
            'applied' => $result->applied,
            'duplicate' => $result->duplicate,
            'seq' => $result->seq,
            'idempotency_key' => $idempotency,
        ], JsonResponse::HTTP_ACCEPTED);
    }

    private function requireUser(Request $request): User
    {
        $user = $request->attributes->get('current_user');
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        return $user;
    }

    private function normalizeCommand(string $command): string
    {
        $command = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $command) ?? '';
        $command = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $command);
        $command = preg_replace('/\s+/u', ' ', $command) ?? '';

        return trim($command);
    }

    private function requiresCsrf(Request $request): bool
    {
        if (str_starts_with(strtolower((string) $request->headers->get('Authorization', '')), 'bearer ')) {
            return false;
        }

        return $request->cookies->count() > 0;
    }

    private function isCsrfValid(Request $request, int $instanceId, string $fallbackBodyToken): bool
    {
        $token = trim((string) ($request->headers->get('X-CSRF-Token') ?? $fallbackBodyToken));
        if ($token === '') {
            return false;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken('instance_console_' . $instanceId, $token));
    }

    private function audit(User $actor, string $result, int $instanceId, ?string $command, string $reason): void
    {
        $command = $command ?? '';
        $hash = hash_hmac('sha256', $command, $this->auditHmacKey);
        $this->auditLogger->log($actor, 'instance.console.'.$result, [
            'instance_id' => $instanceId,
            'result' => $result,
            'reason' => $reason,
            'command_hash' => $hash,
            'command_length' => strlen($command),
        ]);
    }
}
