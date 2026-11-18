<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\MetricsRetentionService;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class MetricsCleanupScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private MetricsRetentionService $retention, private CacheInterface $cache) {}
    #[\Override]
    public function type(): string { return 'cleanup.metrics'; }
    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', $this->type(), 'Metrics Cleanup', $this->type(), 'core', '0 * * * *', true)];
    }
    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $now ??= new \DateTimeImmutable();
        $executed = false;
        $deleted = $this->cache->get('retention.metrics.'.intdiv($now->getTimestamp(), 3600), function (ItemInterface $item) use ($now, &$executed): int {
            $item->expiresAfter(7200);
            $executed = true;
            return array_sum($this->retention->cleanup($now));
        });
        return $executed ? ScheduleExecutionResult::success(sprintf('Deleted %d expired metric rows.', $deleted)) : ScheduleExecutionResult::skipped('Metrics cleanup already processed this hour.');
    }
    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $source === 'system' && $id === $this->type() ? ScheduleExecutionResult::success(sprintf('Deleted %d expired metric rows.', array_sum($this->retention->cleanup($now)))) : ScheduleExecutionResult::failed('Unknown metrics cleanup schedule.');
    }
}
