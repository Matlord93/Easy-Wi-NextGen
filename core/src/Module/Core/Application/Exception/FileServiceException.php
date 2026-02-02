<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Exception;

final class FileServiceException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $statusCode = 502,
        private readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
