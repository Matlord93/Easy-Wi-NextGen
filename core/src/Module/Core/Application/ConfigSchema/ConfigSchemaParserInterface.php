<?php

declare(strict_types=1);

namespace App\Module\Core\Application\ConfigSchema;

interface ConfigSchemaParserInterface
{
    public function format(): string;

    /**
     * @param array<string, mixed> $schema
     */
    public function parse(string $content, array $schema): ConfigSchemaParseResult;

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $values
     */
    public function generate(array $schema, array $values): string;
}
