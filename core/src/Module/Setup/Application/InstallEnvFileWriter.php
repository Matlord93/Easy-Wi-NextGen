<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

/** @deprecated use EnvFileWriter */
final class InstallEnvFileWriter
{
    private readonly EnvFileWriter $writer;

    public function __construct(?EnvFileWriter $writer = null)
    {
        $this->writer = $writer ?? new EnvFileWriter();
    }

    /**
     * @param array<string, string> $values
     */
    public function ensureValues(string $path, array $values): void
    {
        $projectDir = dirname($path);
        $sourceFiles = [$projectDir . '/.env', $projectDir . '/.env.local'];
        $this->writer->setMissingKeys($path, $values, $sourceFiles);
    }
}
