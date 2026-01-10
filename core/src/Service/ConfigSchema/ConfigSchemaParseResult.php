<?php

declare(strict_types=1);

namespace App\Service\ConfigSchema;

final class ConfigSchemaParseResult
{
    /**
     * @param array<string, mixed> $values
     * @param string[] $warnings
     */
    public function __construct(
        private readonly array $values,
        private readonly array $warnings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
