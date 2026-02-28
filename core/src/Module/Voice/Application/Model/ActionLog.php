<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Model;

final readonly class ActionLog
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        private string $serverId,
        private string $action,
        private string $actor,
        private \DateTimeImmutable $at,
        private array $metadata = [],
    ) {
    }

    public function serverId(): string { return $this->serverId; }
    public function action(): string { return $this->action; }
    public function actor(): string { return $this->actor; }
    public function at(): \DateTimeImmutable { return $this->at; }

    /** @return array<string, scalar|null> */
    public function metadata(): array { return $this->metadata; }
}
