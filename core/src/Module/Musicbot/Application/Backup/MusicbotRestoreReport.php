<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

final class MusicbotRestoreReport
{
    /**
     * @param list<string> $restored
     * @param list<string> $warnings
     * @param list<string> $missing
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $dryRun,
        public readonly array $restored,
        public readonly array $warnings,
        public readonly array $missing,
        public readonly ?string $error,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'dry_run' => $this->dryRun,
            'restored' => $this->restored,
            'warnings' => $this->warnings,
            'missing' => $this->missing,
            'error' => $this->error,
        ];
    }
}
