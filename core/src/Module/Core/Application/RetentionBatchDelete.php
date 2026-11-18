<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Doctrine\DBAL\Connection;

/** Small independent statements avoid holding a transaction over an entire history. */
final readonly class RetentionBatchDelete
{
    public function __construct(private Connection $db) {}

    public function olderThan(string $table, string $column, string $cutoff, int $batch = 1000): int
    {
        if (!in_array([$table, $column], [['metric_samples', 'recorded_at'], ['instance_metric_samples', 'collected_at'], ['metric_aggregates', 'bucket_start']], true)) {
            throw new \InvalidArgumentException('Unsupported retention table.');
        }
        $batch = max(1, min(5000, $batch));
        $deleted = 0;
        for ($step = 0; $step < 10; ++$step) {
            // The derived table is required for MySQL's same-table DELETE restriction.
            $affected = (int) $this->db->executeStatement(sprintf('DELETE FROM %s WHERE id IN (SELECT id FROM (SELECT id FROM %s WHERE %s < :cutoff ORDER BY %s, id LIMIT %d) expired_rows)', $table, $table, $column, $column, $batch), ['cutoff' => $cutoff]);
            $deleted += $affected;
            if ($affected < $batch) {
                break;
            }
        }
        return $deleted;
    }
}
