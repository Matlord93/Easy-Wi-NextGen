<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum MailBackend: string
{
    case None = 'none';
    case Local = 'local';
    case Panel = 'panel';
    case External = 'external';

    public static function normalize(mixed $value): self
    {
        $candidate = is_string($value) ? strtolower(trim($value)) : '';

        return self::tryFrom($candidate) ?? self::Local;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $backend): string => $backend->value, self::cases());
    }
}
