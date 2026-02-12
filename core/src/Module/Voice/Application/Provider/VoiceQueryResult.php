<?php

declare(strict_types=1);

namespace App\Module\Voice\Application\Provider;

final class VoiceQueryResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $players,
        public readonly ?int $maxPlayers,
        public readonly ?int $channels,
        public readonly \DateTimeImmutable $checkedAt,
        public readonly ?string $reason,
        public readonly ?string $errorCode,
    ) {
    }

    /** @return array{status:string,players:?int,max:?int,channels:?int,checked_at:string,reason:?string,error_code:?string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'players' => $this->players,
            'max' => $this->maxPlayers,
            'channels' => $this->channels,
            'checked_at' => $this->checkedAt->format(DATE_RFC3339),
            'reason' => $this->reason,
            'error_code' => $this->errorCode,
        ];
    }
}
