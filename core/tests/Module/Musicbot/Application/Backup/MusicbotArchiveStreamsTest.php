<?php

declare(strict_types=1);

namespace App\Tests\Module\Musicbot\Application\Backup;

use App\Module\Core\Application\Backup\BackupOperationHeartbeat;
use App\Module\Musicbot\Application\Backup\MusicbotArchiveStreams;
use PHPUnit\Framework\TestCase;

final class MusicbotArchiveStreamsTest extends TestCase
{
    private string $path;
    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'musicbot-media-stream-');
        file_put_contents($this->path, str_repeat('actual binary media '."\0", 120_000));
    }
    protected function tearDown(): void { unlink($this->path); }

    public function testLargeMediaChecksumKeepsHeartbeatAndExactBinaryBytes(): void
    {
        $heartbeat = new BackupOperationHeartbeat();
        $calls = 0;
        $heartbeat->enter(static function () use (&$calls): void { ++$calls; });
        $streams = new MusicbotArchiveStreams($heartbeat);
        self::assertSame(hash_file('sha256', $this->path), $streams->checksum($this->path));
        self::assertGreaterThanOrEqual(2, $calls, 'Checksum performs progress and completion lease checks.');
    }

    public function testLostLeaseDuringHashCannotProduceCompletedChecksum(): void
    {
        $heartbeat = new BackupOperationHeartbeat();
        $calls = 0;
        $heartbeat->enter(static function () use (&$calls): void {
            if (++$calls === 2) { throw new \RuntimeException('Lease ownership lost.'); }
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lease ownership lost');
        (new MusicbotArchiveStreams($heartbeat))->checksum($this->path);
    }

}
