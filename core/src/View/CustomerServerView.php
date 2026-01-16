<?php

declare(strict_types=1);

namespace App\View;

final class CustomerServerView
{
    /**
     * @param array<int, array{label: string, port: int}> $ports
     * @param array<int, string> $setupMissingFields
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $gameKey,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $lastSeenAt,
        public readonly ?string $lastActionLabel,
        public readonly ?\DateTimeImmutable $lastActionAt,
        public readonly string $instanceRoot,
        public readonly array $ports,
        public readonly ?string $address,
        public readonly ?string $quickConnect,
        public readonly array $setupMissingFields,
        public readonly bool $setupRequired,
        public readonly ?string $currentVersion,
        public readonly ?string $lockedVersion,
        public readonly ?string $nodeName,
        public readonly ?string $nodeId,
        public readonly ?string $processId,
        public readonly ?string $lastError,
        public readonly bool $installReady,
        public readonly ?string $installErrorCode,
    ) {
    }
}
