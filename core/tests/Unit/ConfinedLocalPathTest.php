<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Filesystem\ConfiguredKeyFile;
use App\Infrastructure\Filesystem\ConfinedLocalPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfinedLocalPathTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/easywi-confined-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/root', 0700, true);
        file_put_contents($this->directory.'/secret', 'outside secret');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($this->directory);
    }

    #[DataProvider('unsafePaths')]
    public function testRejectsTraversalWrappersAndNetworkPaths(string $relative): void
    {
        self::assertNull(ConfinedLocalPath::resolve($this->directory.'/root', $relative));
    }

    public static function unsafePaths(): iterable
    {
        foreach (['../secret', 'nested/../../secret', '/etc/passwd', 'php://filter/resource=/etc/passwd', 'http://127.0.0.1/key', 'file:///etc/passwd', '//server/share', '\\\\server\\share', 'file:stream', 'C:/secret', "a\0b"] as $path) {
            yield [$path];
        }
    }

    public function testChecksExistingAncestorsForNewTargetsAndRejectsDanglingLinks(): void
    {
        if (!@symlink($this->directory, $this->directory.'/root/escape')) {
            self::markTestSkipped('Symlinks are unavailable.');
        }
        symlink($this->directory.'/secret', $this->directory.'/root/file');
        symlink($this->directory.'/missing', $this->directory.'/root/dangling');
        self::assertNull(ConfinedLocalPath::resolve($this->directory.'/root', 'escape/new/file'));
        self::assertNull(ConfinedLocalPath::resolve($this->directory.'/root', 'file', true));
        self::assertNull(ConfinedLocalPath::resolve($this->directory.'/root', 'dangling/new'));
        self::assertSame($this->directory.'/root/new/file', ConfinedLocalPath::resolve($this->directory.'/root', 'new/file'));
        self::assertNull(ConfinedLocalPath::resolve('php://filter', 'key'));
    }

    public function testConfiguredKeyReaderPreservesBytesAndLimitsSize(): void
    {
        $path = $this->directory.'/root/key';
        file_put_contents($path, "synthetic key\n");
        self::assertSame("synthetic key\n", ConfiguredKeyFile::read($path));
        file_put_contents($path, str_repeat('x', 65537));
        $this->expectException(\RuntimeException::class);
        ConfiguredKeyFile::read($path);
    }

    #[DataProvider('nonLocalKeys')]
    public function testConfiguredKeyReaderNeverFetchesUrls(string $path): void
    {
        $this->expectException(\RuntimeException::class);
        ConfiguredKeyFile::read($path);
    }

    public static function nonLocalKeys(): iterable
    {
        yield ['http://127.0.0.1:8006/key'];
        yield ['php://filter/convert.base64-encode/resource=/etc/passwd'];
        yield ['data://text/plain,secret'];
        yield ['relative/key'];
        yield ['\\\\server\\share\\key'];
    }
}
