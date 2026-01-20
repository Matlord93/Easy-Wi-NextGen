<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

final class QueryResult
{
    public function __construct(
        private readonly ?string $status,
        private readonly ?int $players,
        private readonly ?int $maxPlayers,
        private readonly ?string $message = null,
    ) {
    }

    public static function unavailable(?string $message = null): self
    {
        return new self('unknown', null, null, $message);
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getPlayers(): ?int
    {
        return $this->players;
    }

    public function getMaxPlayers(): ?int
    {
        return $this->maxPlayers;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCacheArray(
        \DateTimeImmutable $checkedAt,
        string $source,
    ): array {
        return [
            'status' => $this->status,
            'players' => $this->players,
            'max_players' => $this->maxPlayers,
            'message' => $this->message,
            'checked_at' => $checkedAt->format(DATE_ATOM),
            'source' => $source,
        ];
    }
}
