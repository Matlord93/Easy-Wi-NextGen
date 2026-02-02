<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class QueryError
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private readonly string $code,
        private readonly string $message,
        private readonly array $details = [],
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
