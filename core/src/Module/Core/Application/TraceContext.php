<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class TraceContext
{
    public const REQUEST_HEADER = 'X-Request-ID';
    public const CORRELATION_HEADER = 'X-Correlation-ID';
    public const REQUEST_ATTRIBUTE = 'request_id';
    public const CORRELATION_ATTRIBUTE = 'correlation_id';

    public function requestId(Request $request): string
    {
        $requestId = $this->resolveHeaderOrAttribute($request, self::REQUEST_HEADER, self::REQUEST_ATTRIBUTE);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $requestId);

        return $requestId;
    }

    public function correlationId(Request $request): string
    {
        $header = trim((string) ($request->headers->get(self::CORRELATION_HEADER) ?? ''));
        if ($this->isValidUuid($header)) {
            return $header;
        }

        $attribute = trim((string) ($request->attributes->get(self::CORRELATION_ATTRIBUTE) ?? ''));
        if ($this->isValidUuid($attribute)) {
            return $attribute;
        }

        $requestId = $this->requestId($request);
        $request->attributes->set(self::CORRELATION_ATTRIBUTE, $requestId);

        return $requestId;
    }

    public function newId(): string
    {
        return Uuid::v4()->toRfc4122();
    }

    public function isValidUuid(string $value): bool
    {
        return $value !== '' && Uuid::isValid($value);
    }

    private function resolveHeaderOrAttribute(Request $request, string $header, string $attribute): string
    {
        $headerValue = trim((string) ($request->headers->get($header) ?? ''));
        if ($this->isValidUuid($headerValue)) {
            return $headerValue;
        }

        $attributeValue = trim((string) ($request->attributes->get($attribute) ?? ''));

        return $this->isValidUuid($attributeValue) ? $attributeValue : $this->newId();
    }
}

