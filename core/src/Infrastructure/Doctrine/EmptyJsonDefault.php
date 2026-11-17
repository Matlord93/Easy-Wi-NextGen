<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\DefaultExpression;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class EmptyJsonDefault implements DefaultExpression
{
    private function __construct(private string $literal)
    {
    }

    public static function fromValue(mixed $value): ?self
    {
        if (!is_string($value)) {
            return null;
        }
        if ($value === '{}' || $value === '[]') {
            return new self($value);
        }
        $value = str_replace("\\'", "'", $value);
        if (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $value = substr($value, 1, -1);
        }
        if (preg_match("/^(?:_[a-zA-Z0-9_]+)?'(\\{\\}|\\[\\])'$/D", $value, $matches) === 1) {
            return new self($matches[1]);
        }

        return null;
    }

    #[\Override]
    public function toSQL(AbstractPlatform $platform): string
    {
        return '('.$platform->quoteStringLiteral($this->literal).')';
    }
}
