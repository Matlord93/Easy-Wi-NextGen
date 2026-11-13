<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Command;

use App\Module\Gameserver\Application\Console\ConsoleAgentGrpcClientInterface;
use App\Module\Gameserver\Application\Console\ConsoleEventBusInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\ResetInterface;

#[AsCommand(name: 'app:console:relay', description: 'Optional legacy SSE relay; the integrated live console does not need this worker.')]
final class ConsoleRelayCommand extends Command
{
    private int $lastHeartbeatAt = 0;
    private float $deadline = PHP_FLOAT_MAX;
    private int $memoryLimit = PHP_INT_MAX;

    public function __construct(
        private readonly ConsoleAgentGrpcClientInterface $grpcClient,
        private readonly ConsoleEventBusInterface $eventBus,
        private readonly LoggerInterface $logger,
        private readonly ?\Redis $redis = null,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?\Symfony\Contracts\HttpClient\HttpClientInterface $httpClient = null,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Gracefully recycle after this many seconds.', '3600');
        $this->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Gracefully recycle at this PHP heap size in MiB.', '96');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $seconds = (int) $input->getOption('time-limit');
        $mib = (int) $input->getOption('memory-limit');
        if ($seconds < 1 || $mib < 16) {
            $output->writeln('time-limit must be positive and memory-limit at least 16 MiB.');
            return Command::INVALID;
        }
        $this->deadline = microtime(true) + $seconds;
        $this->memoryLimit = $mib * 1024 * 1024;
        while (!$this->shouldRecycle()) {
            $this->touchHeartbeat();
            foreach ($this->eventBus->getInstancesWithSubscribers() as $instanceId) {
                if ($this->shouldRecycle()) {
                    break;
                }
                try {
                    $this->relayInstance($instanceId);
                } finally {
                    $this->entityManager?->clear();
                    if ($this->logger instanceof ResetInterface || $this->logger instanceof \Monolog\ResettableInterface) {
                        $this->logger->reset();
                    }
                    if ($this->httpClient instanceof ResetInterface) {
                        $this->httpClient->reset();
                    }
                    gc_collect_cycles();
                }
            }
            usleep(200000);
        }
        $output->writeln('Legacy console worker recycled within configured time/memory limits.');
        return Command::SUCCESS;
    }

    private function shouldRecycle(): bool
    {
        return microtime(true) >= $this->deadline || memory_get_usage(true) >= $this->memoryLimit;
    }

    private function relayInstance(int $instanceId): void
    {
        if ($this->eventBus->getSubscriberCount($instanceId) < 1) {
            return;
        }
        $started = microtime(true);
        $count = 0;
        $this->touchHeartbeat();
        try {
            foreach ($this->grpcClient->attachStream($instanceId) as $event) {
                if ($this->shouldRecycle() || $this->eventBus->getSubscriberCount($instanceId) < 1) {
                    return;
                }
                $this->touchHeartbeat();
                $chunk = (string) ($event['chunk_bytes'] ?? $event['chunk'] ?? '');
                $this->eventBus->publishConsoleEvent($instanceId, [
                    'type' => 'chunk', 'ts' => $event['ts'] ?? (new \DateTimeImmutable())->format(DATE_ATOM),
                    'instance_id' => $instanceId, 'chunk_base64' => base64_encode($chunk),
                    'seq' => $event['seq'] ?? null, 'status' => $event['status'] ?? null,
                ]);
                // Fair turns: a continuously writing game cannot monopolize the worker.
                if (++$count >= 200 || microtime(true) - $started >= 1) {
                    return;
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Legacy console relay reconnect.', ['instance_id' => $instanceId, 'type' => $exception::class]);
            usleep(200000);
        } finally {
            $this->touchHeartbeat();
        }
    }

    private function touchHeartbeat(): void
    {
        $now = time();
        if ($now - $this->lastHeartbeatAt < 5) {
            return;
        } $this->lastHeartbeatAt = $now;
        if (!$this->redis instanceof \Redis) {
            return;
        }
        try {
            $this->redis->set('console_relay:heartbeat', (string) $now);
        } catch (\Throwable) { /* Heartbeat must not accumulate buffered reconnect logs. */
        }
    }
}
