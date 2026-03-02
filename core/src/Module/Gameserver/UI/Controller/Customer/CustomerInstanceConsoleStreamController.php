<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\Console\ConsoleEventBusInterface;
use App\Module\Gameserver\Application\Console\ConsoleStreamDiagnostics;
use App\Repository\InstanceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/instances')]
final class CustomerInstanceConsoleStreamController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly ConsoleEventBusInterface $eventBus,
        private readonly int $maxDurationSeconds = 1800,
        private readonly int $pingIntervalSeconds = 15,
        private readonly ?ConsoleStreamDiagnostics $diagnostics = null,
    ) {
    }

    #[Route(path: '/{id}/console/stream', name: 'customer_instance_console_stream', methods: ['GET'])]
    #[Route(path: '/{id}/console/stream/', name: 'customer_instance_console_stream_slash', methods: ['GET'])]
    public function stream(Request $request, int $id): StreamedResponse
    {
        $actor = $this->requireUser($request);
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }

        $isOwner = $instance->getCustomer()?->getId() === $actor->getId();
        $isAdmin = $actor->getType() === UserType::Admin;
        if (!$isOwner && !$isAdmin) {
            throw new AccessDeniedHttpException('Forbidden');
        }

        $correlationId = $this->resolveCorrelationId($request);
        $lastEventId = $this->resolveLastEventId($request);

        $response = new StreamedResponse(function () use ($id, $lastEventId, $correlationId): void {
            try {
                $degraded = $this->resolveDegradedCause();
                if ($degraded !== null) {
                    $this->writeEvent([
                        'type' => 'status',
                        'status' => $degraded['status'],
                        'message' => $degraded['message'],
                        'correlation_id' => $correlationId,
                        'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    ], 'status', null);

                    return;
                }
                $startedAt = time();
                $lastPing = time();
                $this->eventBus->incrementSubscriber($id);

                foreach ($this->eventBus->replayConsoleEvents($id, $lastEventId) as $event) {
                    $event['correlation_id'] ??= $correlationId;
                    $this->writeEvent($event);
                }

                while ((time() - $startedAt) < $this->maxDurationSeconds) {
                    if (connection_aborted()) {
                        break;
                    }
                    $this->eventBus->refreshSubscriberTtl($id);
                    $this->eventBus->consumeConsoleEvents(
                        $id,
                        function (array $event) use ($correlationId): void {
                            $event['correlation_id'] ??= $correlationId;
                            $this->writeEvent($event);
                        },
                        fn (): bool => (time() - $startedAt) >= $this->maxDurationSeconds || connection_aborted(),
                    );

                    if ((time() - $lastPing) >= $this->pingIntervalSeconds) {
                        $this->writeEvent([
                            'type' => 'ping',
                            'correlation_id' => $correlationId,
                            'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
                        ], 'ping', null);
                        $lastPing = time();
                    }
                    usleep(200000);
                }
            } catch (\Throwable) {
                $this->writeEvent([
                    'type' => 'status',
                    'status' => 'stream_unavailable',
                    'message' => 'Console stream unavailable',
                    'correlation_id' => $correlationId,
                    'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ], 'status', null);
            } finally {
                try {
                    $this->eventBus->decrementSubscriber($id);
                } catch (\Throwable) {
                    // stream is ending already; suppress transport shutdown errors.
                }
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('X-Console-Resume-Offset', (string) $lastEventId);

        return $response;
    }

    private function resolveLastEventId(Request $request): int
    {
        $header = trim((string) $request->headers->get('Last-Event-ID', ''));
        if ($header !== '' && ctype_digit($header)) {
            return (int) $header;
        }

        $offset = trim((string) $request->query->get('last_offset', ''));
        if ($offset !== '' && ctype_digit($offset)) {
            return (int) $offset;
        }

        $cursor = trim((string) $request->query->get('cursor', ''));
        if ($cursor !== '' && ctype_digit($cursor)) {
            return (int) $cursor;
        }

        return 0;
    }

    private function resolveCorrelationId(Request $request): string
    {
        $raw = trim((string) ($request->headers->get('X-Correlation-ID') ?? $request->headers->get('X-Request-ID', '')));
        if ($raw !== '') {
            return substr($raw, 0, 64);
        }

        return bin2hex(random_bytes(8));
    }

    /** @return array{status:string,message:string}|null */
    private function resolveDegradedCause(): ?array
    {
        if (!$this->diagnostics instanceof ConsoleStreamDiagnostics) {
            return null;
        }

        if ($this->diagnostics->isNullClient()) {
            return ['status' => 'backend_not_configured', 'message' => 'Console backend not configured'];
        }

        if (!$this->diagnostics->redisPingOk()) {
            return ['status' => 'redis_unavailable', 'message' => 'Redis unavailable'];
        }

        $age = $this->diagnostics->relayHeartbeatAgeSeconds();
        if ($age === null || $age > 20) {
            return ['status' => 'relay_stale', 'message' => 'Console relay heartbeat stale'];
        }

        return null;
    }

    private function requireUser(Request $request): User
    {
        $user = $request->attributes->get('current_user');
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        return $user;
    }

    private function writeEvent(array $payload, string $event = 'chunk', ?int $id = null): void
    {
        $seq = $id ?? (int) ($payload['seq'] ?? 0);
        if ($seq > 0) {
            echo sprintf("id: %d\n", $seq);
        }
        echo sprintf("event: %s\n", $event ?: (string) ($payload['type'] ?? 'chunk'));
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES)."\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }
}
