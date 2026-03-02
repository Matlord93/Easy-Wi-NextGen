<?php

declare(strict_types=1);

namespace App\Module\Core\UI\Exception;

enum ApiErrorCode: string
{
    case InvalidJson = 'INVALID_JSON';
    case Unauthorized = 'UNAUTHORIZED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case Conflict = 'CONFLICT';
    case ValidationFailed = 'VALIDATION_FAILED';
    case InternalError = 'INTERNAL_ERROR';

    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidJson, self::ValidationFailed => 400,
            self::Unauthorized => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict => 409,
            self::InternalError => 500,
        };
    }
}

