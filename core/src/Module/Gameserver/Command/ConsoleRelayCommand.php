<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Command;

use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleEventBusInterface;
use App\Module\Gameserver\Application\Console\NodeEndpointMissingException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:console:relay', description: 'Relays gRPC console stream to Redis SSE channels.')]
final class ConsoleRelayCommand extends Command
{
    public function __construct(
        private readonly ConsoleAgentGrpcClientInterface $grpcClient,
        private readonly ConsoleEventBusInterface $eventBus,
        private readonly LoggerInterface $logger,
        private readonly ?\Redis $redis = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lastHeartbeat = 0;
        while (true) {
            $now = time();
            if ($this->redis instanceof \Redis && ($now - $lastHeartbeat) >= 5) {
                try {
                    $this->redis->set('console_relay:heartbeat', (string) $now);
                } catch (\Throwable) {
                    // best effort heartbeat.
                }
                $lastHeartbeat = $now;
            }
            $instanceIds = $this->eventBus->getInstancesWithSubscribers();
            if ($instanceIds === []) {
                usleep(500000);
                continue;
            }

            foreach ($instanceIds as $instanceId) {
                $this->relayInstance($instanceId);
            }
            usleep(200000);
        }
    }

    private function relayInstance(int $instanceId): void
    {
        $attempt = 0;
        $lastEventAt = time();

        while ($this->eventBus->getSubscriberCount($instanceId) > 0) {
            try {
                foreach ($this->grpcClient->attachStream($instanceId) as $event) {
                    $payload = [
                        'type' => 'chunk',
                        'ts' => $event['ts'] ?? (new \DateTimeImmutable())->format(DATE_ATOM),
                        'instance_id' => $instanceId,
                        'chunk_base64' => isset($event['chunk_bytes']) ? base64_encode((string) $event['chunk_bytes']) : (isset($event['chunk']) ? base64_encode((string) $event['chunk']) : null),
                        'seq' => $event['seq'] ?? null,
                        'status' => $event['status'] ?? null,
                    ];
                    $this->eventBus->publishConsoleEvent($instanceId, $payload);
                    $lastEventAt = time();
                }
                $attempt = 0;
            } catch (NodeEndpointMissingException $e) {
                $this->logger->warning('console relay node endpoint missing', ['instance_id' => $instanceId]);
                $this->eventBus->publishConsoleEvent($instanceId, [
                    'type' => 'status',
                    'status' => 'node_endpoint_missing',
                    'instance_id' => $instanceId,
                    'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ]);
                usleep(2_000_000);
            } catch (\Throwable $e) {
                $attempt++;
                $sleepMs = min(30_000, 1000 * (2 ** min($attempt, 5)) + random_int(0, 300));
                $this->logger->warning('console relay reconnect', ['instance_id' => $instanceId, 'attempt' => $attempt, 'correlation_id' => bin2hex(random_bytes(6))]);
                usleep($sleepMs * 1000);
            }

            if ((time() - $lastEventAt) >= 15) {
                $this->eventBus->publishConsoleEvent($instanceId, [
                    'type' => 'ping',
                    'instance_id' => $instanceId,
                    'ts' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ]);
                $lastEventAt = time();
            }
        }
    }
}
