<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Infrastructure\Filesystem\WritablePathProbe;
use PHPUnit\Framework\TestCase;

final class WritablePathProbeTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            foreach (glob($directory . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
                if (basename($path) === '.' || basename($path) === '..') {
                    continue;
                }

                if (is_file($path)) {
                    chmod($path, 0600);
                    unlink($path);
                }
            }

            @rmdir($directory);
        }

        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    public function testDirectoryUsesRealWriteProbeAndCleansUpProbeFile(): void
    {
        $directory = $this->createTemporaryDirectory();

        self::assertTrue(WritablePathProbe::directory($directory));
        self::assertSame([], glob($directory . '/.easywi-write-test-*') ?: []);
    }

    public function testFileTargetAcceptsExistingFileWhenParentDirectoryCanReplaceIt(): void
    {
        $directory = $this->createTemporaryDirectory();
        $path = $directory . '/db.json';
        file_put_contents($path, '{}');
        chmod($path, 0400);

        try {
            self::assertTrue(WritablePathProbe::fileTarget($path));
        } finally {
            chmod($path, 0600);
        }
    }

    public function testDirectoryOrCreatableAcceptsMissingDirectoryWhenParentIsWritable(): void
    {
        $directory = $this->createTemporaryDirectory();

        self::assertTrue(WritablePathProbe::directoryOrCreatable($directory . '/easywi'));
    }

    public function testDirectoryRejectsMissingDirectory(): void
    {
        $directory = $this->createTemporaryDirectory();

        self::assertFalse(WritablePathProbe::directory($directory . '/missing'));
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/easywi-writable-probe-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
