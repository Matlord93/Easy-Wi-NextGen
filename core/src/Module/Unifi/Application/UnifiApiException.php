<?php

declare(strict_types=1);

namespace App\Module\Unifi\Application;

final class UnifiApiException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
