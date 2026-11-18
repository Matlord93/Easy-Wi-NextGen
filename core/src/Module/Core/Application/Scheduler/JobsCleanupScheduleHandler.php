<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Scheduler;

use App\Module\Core\Application\JobHistoryRetention;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class JobsCleanupScheduleHandler implements ScheduleHandlerInterface
{
    public function __construct(private JobHistoryRetention $retention, private CacheInterface $cache) {}
    #[\Override]
    public function type(): string { return 'cleanup.jobs'; }
    #[\Override]
    public function schedules(): array
    {
        return [new InternalSchedule('system', $this->type(), 'Job Cleanup', $this->type(), 'core', '15 * * * *', true)];
    }
    #[\Override]
    public function runDue(?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        $now ??= new \DateTimeImmutable();
        $executed = false;
        $deleted = $this->cache->get('retention.jobs.'.intdiv($now->getTimestamp(), 3600), function (ItemInterface $item) use ($now, &$executed): int {
            $item->expiresAfter(7200);
            $executed = true;
            return array_sum($this->retention->cleanup($now));
        });
        return $executed ? ScheduleExecutionResult::success(sprintf('Deleted %d expired jobs/task runs.', $deleted)) : ScheduleExecutionResult::skipped('Job cleanup already processed this hour.');
    }
    #[\Override]
    public function runNow(string $source, string $id, ?\DateTimeImmutable $now = null): ScheduleExecutionResult
    {
        return $source === 'system' && $id === $this->type() ? ScheduleExecutionResult::success(sprintf('Deleted %d expired jobs/task runs.', array_sum($this->retention->cleanup($now)))) : ScheduleExecutionResult::failed('Unknown job cleanup schedule.');
    }
}
