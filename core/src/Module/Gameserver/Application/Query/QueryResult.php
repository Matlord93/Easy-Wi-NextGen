<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Gameserver\Application\Query\QueryResultNormalizer;

final class QueryResult
{
    public function __construct(
        private readonly ?string $status,
        private readonly ?int $players,
        private readonly ?int $maxPlayers,
        private readonly ?string $message = null,
        /**
         * @var array<string, mixed>|null
         */
        private readonly ?array $normalized = null,
    ) {
    }

    public static function unavailable(?string $message = null): self
    {
        $normalized = QueryResultNormalizer::build(
            null,
            null,
            null,
            $message,
            [],
            [],
        );

        return new self('unknown', null, null, $message, $normalized);
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
     * @return array<string, mixed>|null
     */
    public function getNormalized(): ?array
    {
        return $this->normalized;
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
            'result' => $this->normalized,
            'checked_at' => $checkedAt->format(DATE_ATOM),
            'source' => $source,
        ];
    }
}
