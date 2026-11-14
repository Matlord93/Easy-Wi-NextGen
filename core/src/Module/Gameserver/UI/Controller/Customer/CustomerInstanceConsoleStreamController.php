<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Infrastructure\Http\RequestActor;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\Console\ConsoleEventBusInterface;
use App\Module\Gameserver\Application\Console\ConsoleStreamDiagnostics;
use App\Repository\InstanceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\ServerEvent;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;
use App\Module\Core\Attribute\RequiresModule;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/instances')]
#[RequiresModule('game')]
final class CustomerInstanceConsoleStreamController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly ConsoleEventBusInterface $eventBus,
        private readonly TranslatorInterface $translator,
        private readonly int $maxDurationSeconds = 1800,
        private readonly int $pingIntervalSeconds = 15,
        private readonly ?ConsoleStreamDiagnostics $diagnostics = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $debug = false,
    ) {
    }

    #[Route(path: '/{id}/console/stream', name: 'customer_instance_console_stream', methods: ['GET'])]
    #[Route(path: '/{id}/console/stream/', name: 'customer_instance_console_stream_slash', methods: ['GET'])]
    public function stream(Request $request, int $id): StreamedResponse
    {
        $actor = $this->requireUser($request);
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException($this->translator->trans('gs_api_instance_not_found'));
        }

        $isOwner = $instance->getCustomer()?->getId() === $actor->getId();
        $isAdmin = $actor->getType() === UserType::Admin;
        if (!$isOwner && !$isAdmin) {
            throw new AccessDeniedHttpException($this->translator->trans('error_forbidden'));
        }

        $lastEventId = max(
            (int) $request->headers->get('Last-Event-ID', '0'),
            (int) $request->query->get('last_event_id', '0'),
        );

        $response = new EventStreamResponse(function (EventStreamResponse $response) use ($id, $lastEventId): void {
            try {
                $degraded = $this->resolveDegradedCause();
                if ($degraded !== null) {
                    $this->writeEvent($response, [
                        'type' => 'status',
                        'status' => $degraded['status'],
                        'message' => $degraded['message'],
                        'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
                    ], 'status', null);

                    return;
                }
                $startedAt = time();
                $lastPing = time();
                $this->eventBus->incrementSubscriber($id);

                foreach ($this->eventBus->replayConsoleEvents($id, $lastEventId) as $event) {
                    $this->writeEvent($response, $event);
                }

                while ((time() - $startedAt) < $this->maxDurationSeconds) {
                    try {
                        $this->eventBus->refreshSubscriberTtl($id);
                    } catch (\Throwable) {
                        // Non-fatal: a transient Redis reconnect failure must not
                        // kill the SSE stream. The subscriber TTL may lapse (the
                        // relay will stop relaying within 60 s) but the stream
                        // itself recovers on the next iteration.
                    }
                    $this->eventBus->consumeConsoleEvents(
                        $id,
                        function (array $event) use ($response): void {
                            $this->writeEvent($response, $event);
                        },
                        fn (): bool => (time() - $startedAt) >= $this->maxDurationSeconds,
                    );

                    if ((time() - $lastPing) >= $this->pingIntervalSeconds) {
                        $this->writeEvent($response, ['type' => 'ping', 'ts' => (new \DateTimeImmutable())->format(DATE_ATOM)], 'ping', null);
                        $lastPing = time();
                    }
                    usleep(200000);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('console stream unavailable', ['instance_id' => $id, 'exception' => $e]);
                $message = 'Console stream unavailable';
                if ($this->debug) {
                    $message .= ' : ' . $e->getMessage() . sprintf(' (%s:%d)', $e->getFile(), $e->getLine());
                }
                $this->writeEvent($response, [
                    'type' => 'status',
                    'status' => 'stream_unavailable',
                    'message' => $message,
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
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
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
        if (!$this->diagnostics->isLegacyRelayEnabled()) {
            return ['status' => 'websocket_required', 'message' => 'This console uses the integrated live WebSocket transport.'];
        }

        // Redis + relay are only needed when the event bus uses pub/sub mode.
        // In direct mode (no Redis configured) the FallbackConsoleEventBus streams
        // straight from the agent, so a missing relay heartbeat is not an error.
        if (!$this->diagnostics->isRelayRequired()) {
            return null;
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
        $user = RequestActor::user($request);
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException($this->translator->trans('error_authentication_required'));
        }

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function writeEvent(EventStreamResponse $response, array $payload, string $event = 'chunk', ?int $id = null): void
    {
        $seq = $id ?? (int) ($payload['seq'] ?? 0);
        $response->sendEvent(new ServerEvent(
            data: json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            type: $event !== '' ? $event : self::eventType($payload['type'] ?? null),
            id: $seq > 0 ? (string) $seq : null,
        ));
    }

    private static function eventType(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : 'chunk';
    }
}
