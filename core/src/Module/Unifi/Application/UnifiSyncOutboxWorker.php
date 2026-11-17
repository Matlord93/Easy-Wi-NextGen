<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

use Psr\Log\LoggerInterface;

final readonly class UnifiSyncOutboxWorker
{
    public function __construct(private UnifiSyncOutbox $outbox, private QueuedUnifiSynchronizerInterface $synchronizer, private LoggerInterface $logger)
    {
    }

    /** @return array{attempted:int,succeeded:int,failed:int,skipped:int} */
    public function drain(int $limit = 20): array
    {
        $result = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->outbox->dueIds($limit) as $instanceId) {
            $claim = $this->outbox->claim($instanceId);
            if ($claim === null) {
                continue;
            }
            ++$result['attempted'];
            try {
                $sync = $this->synchronizer->syncQueued($instanceId, function () use ($claim): void {
                    $this->outbox->renew($claim);
                });
                if ($sync['status'] === 'disabled') {
                    $this->outbox->defer($claim);
                    ++$result['skipped'];
                    continue;
                }
                if ($sync['status'] !== 'success') {
                    throw new \RuntimeException('UniFi synchronization returned '.$sync['status'].'.');
                }
                $this->outbox->acknowledge($claim);
                ++$result['succeeded'];
            } catch (\Throwable $error) {
                $this->outbox->retry($claim, $error->getMessage());
                ++$result['failed'];
                $this->logger->error('unifi.sync_retry', ['instance_id' => $instanceId, 'attempt' => $claim->attempts, 'exception' => $error]);
                // The synchronizer may have closed its EntityManager; retry on a fresh invocation.
                break;
            }
        }
        return $result;
    }
}
