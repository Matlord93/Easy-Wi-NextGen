<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ScheduledTaskRun;
use App\Module\Core\Domain\Enum\JobStatus;
use Doctrine\ORM\QueryBuilder;

final readonly class JobHistoryPolicy
{
    /** @var list<string> */
    public const CUSTOMER_PATTERNS = ['instance.%', 'ts3.virtual.%', 'ts6.virtual.%', 'voice.action.%', 'webspace.%', 'database.%', 'mailbox.%', 'mail.domain.%', 'domain.ssl.%', 'musicbot.%', 'sinusbot.%', 'kvm.vm.%', 'vm.%', 'docker.container.%', 'sniper.install'];

    public function __construct(private AppSettingsServiceInterface $settings) {}

    public function mode(): string
    {
        $mode = $this->settings->getSettings()[AppSettingsService::KEY_JOB_HISTORY_MODE] ?? 'all';
        return in_array($mode, ['all', 'customers', 'errors'], true) ? $mode : 'all';
    }

    public function keepsJob(string $type): bool
    {
        return $this->mode() === 'all' || ($this->mode() === 'customers' && self::isCustomerType($type));
    }

    public static function isCustomerType(string $type): bool
    {
        foreach (self::CUSTOMER_PATTERNS as $pattern) {
            if (str_ends_with($pattern, '%') ? str_starts_with($type, substr($pattern, 0, -1)) : $type === $pattern) {
                return true;
            }
        }
        return false;
    }

    public function storesJobLog(Job $job): bool
    {
        return $job->getStatus() === JobStatus::Failed || $this->keepsJob($job->getType());
    }

    public function storesRun(ScheduledTaskRun $run): bool
    {
        if ($run->getStatus() === 'failed') {
            return true;
        }
        return $this->mode() === 'all' || ($this->mode() === 'customers' && $run->getStatus() !== 'skipped' && self::isCustomerRun($run->getScheduleSource(), $run->getType()));
    }

    public static function isCustomerRun(string $source, string $type): bool
    {
        return in_array($source, ['instance_schedule', 'backup_schedule'], true) || str_starts_with($type, 'gameserver.');
    }

    public function filterJobs(QueryBuilder $query, string $alias = 'job'): QueryBuilder
    {
        if ($this->mode() === 'errors') {
            return $query->andWhere($alias.'.status = :history_failed')->setParameter('history_failed', JobStatus::Failed->value);
        }
        if ($this->mode() === 'customers') {
            $parts = [$alias.'.status = :history_failed'];
            $query->setParameter('history_failed', JobStatus::Failed->value);
            foreach (self::CUSTOMER_PATTERNS as $index => $pattern) {
                $parts[] = $alias.'.type LIKE :history_type_'.$index;
                $query->setParameter('history_type_'.$index, $pattern);
            }
            $query->andWhere('('.implode(' OR ', $parts).')');
        }
        return $query;
    }

    public function filterRuns(QueryBuilder $query): QueryBuilder
    {
        if ($this->mode() === 'errors') {
            $query->andWhere('run.status = :history_failed')->setParameter('history_failed', 'failed');
        } elseif ($this->mode() === 'customers') {
            $query->andWhere("(run.status = :history_failed OR (run.status <> 'skipped' AND (run.scheduleSource IN (:history_sources) OR run.type LIKE :history_type)))")
                ->setParameter('history_failed', 'failed')->setParameter('history_sources', ['instance_schedule', 'backup_schedule'])->setParameter('history_type', 'gameserver.%');
        }
        return $query;
    }
}
