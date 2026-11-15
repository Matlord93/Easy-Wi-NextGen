<?php

declare(strict_types=1);

// Measures only validation/list normalization of 250,000 existing entity references.
// The frozen baseline below is EntityQueryResult before this optimization.
use Doctrine\ORM\AbstractQuery;

require dirname(__DIR__, 2) . '/core/vendor/autoload.php';
class_exists(App\Repository\EntityQueryResult::class);
$rows = array_fill(0, 250000, new stdClass());
$query = new class ($rows) extends Doctrine\ORM\AbstractQuery {
    public function __construct(private array $rows)
    {
    }
    public function getResult(string|int $hydrationMode = self::HYDRATE_OBJECT): mixed
    {
        return $this->rows;
    }
    public function getSQL(): string|array
    {
        return '';
    }
    protected function _doExecute(): Doctrine\DBAL\Result|int
    {
        return 0;
    }
};
$mode = $argv[1] ?? '';
if (!in_array($mode, ['before', 'after'], true)) {
    fwrite(STDERR, "Usage: php scripts/benchmarks/entity-query-results.php before|after\n");
    exit(2);
}
$class = $mode === 'before' ? LegacyEntityQueryResult::class : App\Repository\EntityQueryResult::class;
memory_reset_peak_usage();
$baseline = memory_get_usage();
$start = hrtime(true);
$result = $class::all($query, stdClass::class);
$elapsed = (hrtime(true) - $start) / 1000000;
if (count($result) !== 250000 || $result[0] !== $rows[0] || $result[249999] !== $rows[249999]) {
    throw new RuntimeException('Result mismatch');
}
echo json_encode([
    'version' => PHP_VERSION,
    'mode' => $mode,
    'rows' => count($result),
    'milliseconds' => round($elapsed, 3),
    'additional_peak_bytes' => memory_get_peak_usage() - $baseline,
], JSON_THROW_ON_ERROR), "\n";

final class LegacyEntityQueryResult
{
    public static function all(AbstractQuery $query, string $entityClass): array
    {
        $rows = self::rows($query->getResult());
        $entities = [];
        foreach (array_keys($rows) as $key) {
            $entities[] = self::entity($rows[$key], $entityClass);
        }

        return $entities;
    }

    private static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Entity query did not return an array.');
        }

        return $value;
    }

    private static function entity(mixed $value, string $entityClass): object
    {
        if (!$value instanceof $entityClass) {
            throw new \UnexpectedValueException(sprintf('Entity query returned %s; expected %s.', get_debug_type($value), $entityClass));
        }

        return $value;
    }
}
