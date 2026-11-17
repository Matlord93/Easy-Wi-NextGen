<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

final readonly class BackupRetentionReport
{
    /**
     * @param list<string> $candidates
     * @param list<string> $deleted
     * @param list<array{path:string,reason:string}> $blocked
     */
    public function __construct(public string $planId, public string $status, public string $message, public array $candidates = [], public array $deleted = [], public array $blocked = [])
    {
    }
}
