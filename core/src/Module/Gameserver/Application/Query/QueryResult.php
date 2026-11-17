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
