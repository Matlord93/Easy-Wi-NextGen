<?php

declare(strict_types=1);

namespace App\Module\EasyWiMigration\Application;

final class EasyWiMigrationResult
{
    /** @param list<string> $errors */
    public function __construct(
        public readonly string $entity,
        public readonly int $imported,
        public readonly int $skipped,
        public readonly int $failed,
        public readonly array $errors = [],
    ) {
    }

    public function total(): int
    {
        return $this->imported + $this->skipped + $this->failed;
    }
}
