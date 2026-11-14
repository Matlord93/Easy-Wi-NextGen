<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

/**
 * @psalm-type SyncResult = array{status: string, request_id: string, diff: array{create: UnifiRule[], update: array<int, array{rule: UnifiRule, currentId: string}>, delete: array<int, array{currentId: string, name: string}>}, errors: array<int, array{code: string, message: string}>}
 */
interface UnifiSynchronizerInterface
{
    /** @return SyncResult */
    public function preview(?int $instanceId = null, ?string $requestId = null): array;

    /** @return SyncResult */
    public function sync(?int $instanceId = null, bool $dryRun = false, ?string $requestId = null): array;
}
