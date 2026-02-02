<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\Application\Query;

final class QueryResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly bool $success,
        private readonly string $rawOutput,
        private readonly array $payload = [],
        private readonly ?QueryError $error = null,
        private readonly ?string $actionId = null,
    ) {
    }

    public function success(): bool
    {
        return $this->success;
    }

    public function rawOutput(): string
    {
        return $this->rawOutput;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function error(): ?string
    {
        return $this->error?->message();
    }

    public function errorDetails(): ?QueryError
    {
        return $this->error;
    }

    public function actionId(): ?string
    {
        return $this->actionId;
    }
}
