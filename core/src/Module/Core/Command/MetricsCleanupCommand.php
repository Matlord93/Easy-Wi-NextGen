<?php

declare(strict_types=1);

namespace App\Module\Core\Command;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:metrics:cleanup', description: 'Delete old metric rows in bounded batches.')]
final class MetricsCleanupCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention in days.', '30')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Batch size per delete statement.', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = max(1, (int) $input->getOption('days'));
        $batch = max(100, (int) $input->getOption('batch'));
        $threshold = (new \DateTimeImmutable(sprintf('-%d days', $days)))->format('Y-m-d H:i:s');

        $metricSamplesDeleted = $this->cleanupTable('metric_samples', 'recorded_at', $threshold, $batch);
        $instanceMetricSamplesDeleted = $this->cleanupTable('instance_metric_samples', 'collected_at', $threshold, $batch);
        $aggregateThreshold = (new \DateTimeImmutable(sprintf('-%d days', max($days, 30))))->format('Y-m-d H:i:s');
        $metricAggregatesDeleted = $this->cleanupTable('metric_aggregates', 'bucket_start', $aggregateThreshold, $batch);

        $summary = [
            'threshold' => $threshold,
            'days' => $days,
            'batch' => $batch,
            'metric_samples_deleted' => $metricSamplesDeleted,
            'instance_metric_samples_deleted' => $instanceMetricSamplesDeleted,
            'metric_aggregates_deleted' => $metricAggregatesDeleted,
        ];

        $this->logger->info('metrics.cleanup.completed', $summary);

        $io->success(sprintf(
            'Metrics cleanup finished. metric_samples=%d, instance_metric_samples=%d, metric_aggregates=%d (threshold=%s, batch=%d)',
            $metricSamplesDeleted,
            $instanceMetricSamplesDeleted,
            $metricAggregatesDeleted,
            $threshold,
            $batch,
        ));

        return Command::SUCCESS;
    }

    private function cleanupTable(string $table, string $timestampColumn, string $threshold, int $batch): int
    {
        $totalDeleted = 0;
        $batch = max(100, $batch);

        while (true) {
            $sql = sprintf(
                'DELETE FROM %s WHERE id IN (SELECT id FROM %s WHERE %s < :threshold ORDER BY id ASC LIMIT %d)',
                $table,
                $table,
                $timestampColumn,
                $batch,
            );

            $deleted = $this->connection->executeStatement($sql, ['threshold' => $threshold]);
            if ($deleted <= 0) {
                break;
            }

            $totalDeleted += $deleted;
        }

        return $totalDeleted;
    }
}
