<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Application\MusicbotQuotaServiceInterface;
use App\Module\Musicbot\Application\MusicbotTrackPathResolver;
use App\Module\Musicbot\Application\MusicbotTrackService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotTrack;
use App\Module\Musicbot\Domain\Enum\MusicbotTrackSourceType;
use App\Repository\MusicbotTrackRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MusicbotTrackStorageSecurityTest extends TestCase
{
    private string $directory;
    private User $owner;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/easywi-track-storage-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/var/musicbot/tracks/customer-42', 0700, true);
        mkdir($this->directory.'/var/musicbot/tracks/customer-43', 0700, true);
        file_put_contents($this->directory.'/var/musicbot/tracks/customer-43/secret.mp3', 'foreign file');
        $this->owner = $this->createStub(User::class);
        $this->owner->method('getId')->willReturn(42);
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

    private function service(): MusicbotTrackService
    {
        return new MusicbotTrackService($this->createStub(EntityManagerInterface::class), $this->createStub(MusicbotTrackRepositoryInterface::class), $this->createStub(MusicbotQuotaServiceInterface::class), new MusicbotTrackPathResolver($this->directory), $this->directory);
    }

    private function track(string $path): MusicbotTrack
    {
        $track = new MusicbotTrack($this->owner, 'Test track', MusicbotTrackSourceType::Upload, 'audio/mpeg', str_repeat('a', 64), 0, []);
        $track->setFilePath($path);

        return $track;
    }

    public function testDeleteCannotEscapeItsCustomerOrUseTraversal(): void
    {
        $foreign = $this->directory.'/var/musicbot/tracks/customer-43/secret.mp3';
        $this->service()->deleteTrack($this->owner, $this->track($foreign));
        self::assertFileExists($foreign);
        $this->service()->deleteTrack($this->owner, $this->track($this->directory.'/var/musicbot/tracks/customer-42/../customer-43/secret.mp3'));
        self::assertSame('foreign file', file_get_contents($foreign));
    }

    public function testDeleteCannotFollowASymlinkIntoAnotherCustomersDirectory(): void
    {
        $foreign = $this->directory.'/var/musicbot/tracks/customer-43/secret.mp3';
        $link = $this->directory.'/var/musicbot/tracks/customer-42/link.mp3';
        if (!@symlink($foreign, $link)) {
            self::markTestSkipped('Symlinks unavailable.');
        }
        $this->service()->deleteTrack($this->owner, $this->track($link));
        self::assertFileExists($foreign);
        self::assertTrue(is_link($link));
    }

    public function testUploadCannotFollowASymlinkedCustomerDirectory(): void
    {
        rmdir($this->directory.'/var/musicbot/tracks/customer-42');
        if (!@symlink($this->directory.'/var/musicbot/tracks/customer-43', $this->directory.'/var/musicbot/tracks/customer-42')) {
            self::markTestSkipped('Symlinks unavailable.');
        }
        $this->expectException(\RuntimeException::class);
        (new MusicbotTrackPathResolver($this->directory))->uploadPath(null, 42, str_repeat('a', 16).'-song.mp3');
    }

    public function testInstanceStorageCannotBeRedirectedOutsideItsInstallRoot(): void
    {
        mkdir($this->directory.'/instance');
        if (!@symlink($this->directory.'/var', $this->directory.'/instance/data')) {
            self::markTestSkipped('Symlinks unavailable.');
        }
        $instance = new MusicbotInstance($this->owner, new Agent('node', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']), 'Bot', 'bot', $this->directory.'/instance');
        $this->expectException(\RuntimeException::class);
        (new MusicbotTrackPathResolver($this->directory))->instanceTrackRoot($instance);
    }

    public function testAnOwnedAbsoluteUploadCanBeDeleted(): void
    {
        $path = $this->directory.'/upload.mp3';
        file_put_contents($path, str_repeat('A', 1024));
        $track = $this->service()->uploadTrack($this->owner, new UploadedFile($path, 'song.mp3', 'audio/mpeg', null, true), '<script>alert(1)</script>');
        self::assertFileExists($track->getFilePath());
        $this->service()->deleteTrack($this->owner, $track);
        self::assertFileDoesNotExist($track->getFilePath());
    }
}
