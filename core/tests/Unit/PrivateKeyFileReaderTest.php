<?php

declare(strict_types=1);
namespace App\Tests\Unit;
use App\Infrastructure\Security\PrivateKeyFileReader;
use PHPUnit\Framework\TestCase;
final class PrivateKeyFileReaderTest extends TestCase
{
    public function testRejectsSymlinkOutsideRootAfterReadingValidKey(): void
    {
        $root = sys_get_temp_dir() . '/easywi-key-' . bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root . '/key', 'test-key');
        $outside = tempnam(sys_get_temp_dir(), 'outside-key');
        self::assertIsString($outside);
        symlink($outside, $root . '/escape');
        try {
            $reader = new PrivateKeyFileReader($root);
            self::assertSame('test-key', $reader->read($root . '/key'));
            $this->expectException(\RuntimeException::class);
            $reader->read($root . '/escape');
        } finally {
            unlink($root . '/escape');
            unlink($root . '/key');
            unlink($outside);
            rmdir($root);
        }
    }
}
