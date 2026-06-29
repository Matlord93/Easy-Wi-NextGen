<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Application\MusicbotQuotaServiceInterface;
use App\Module\Musicbot\Application\MusicbotTrackPathResolver;
use App\Module\Musicbot\Application\MusicbotTrackService;
use App\Module\Musicbot\Application\MusicbotWebradioUrlValidator;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\MusicbotTrackRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MusicbotTrackServiceUploadTest extends TestCase
{
    private EntityManagerInterface $em;
    private MusicbotTrackRepositoryInterface $trackRepo;
    private MusicbotQuotaServiceInterface $quota;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->trackRepo = $this->createStub(MusicbotTrackRepositoryInterface::class);
        $this->quota = $this->createStub(MusicbotQuotaServiceInterface::class);
        $this->projectDir = sys_get_temp_dir();
    }

    private function buildService(?MusicbotQuotaServiceInterface $quota = null): MusicbotTrackService
    {
        return new MusicbotTrackService(
            $this->em,
            $this->trackRepo,
            $quota ?? $this->quota,
            new MusicbotTrackPathResolver($this->projectDir),
            $this->projectDir,
            new MusicbotWebradioUrlValidator(),
        );
    }

    private function fakeCustomer(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);

        return $user;
    }

    private function makeTempAudioFile(string $extension = 'mp3'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'musicbot_test_') . '.' . $extension;
        file_put_contents($path, str_repeat('A', 1024));

        return $path;
    }

    public function testUploadAcceptsMp3(): void
    {
        $tmpPath = $this->makeTempAudioFile('mp3');
        $file = new UploadedFile($tmpPath, 'song.mp3', 'audio/mpeg', null, true);

        $service = $this->buildService();
        $customer = $this->fakeCustomer();

        // Quota allows upload (stub returns void by default).
        $track = $service->uploadTrack($customer, $file, 'My Song');

        self::assertSame('My Song', $track->getTitle());
        self::assertSame('audio/mpeg', $track->getMimeType());

        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }


    public function testUploadCreatesCustomerTrackDirectoryRecursively(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/musicbot_upload_' . bin2hex(random_bytes(6));
        $tmpPath = $this->makeTempAudioFile('mp3');
        $file = new UploadedFile($tmpPath, 'song.mp3', 'audio/mpeg', null, true);

        $track = $this->buildService()->uploadTrack($this->fakeCustomer(), $file, 'Nested Song');

        self::assertDirectoryExists($this->projectDir . '/var/musicbot/tracks/customer-42');
        self::assertFileExists((string) $track->getFilePath());
    }

    public function testUploadAcceptsOgg(): void
    {
        $tmpPath = $this->makeTempAudioFile('ogg');
        $file = new UploadedFile($tmpPath, 'track.ogg', 'audio/ogg', null, true);

        $track = $this->buildService()->uploadTrack($this->fakeCustomer(), $file);

        self::assertSame('audio/ogg', $track->getMimeType());
        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    public function testUploadAcceptsWav(): void
    {
        $tmpPath = $this->makeTempAudioFile('wav');
        $file = new UploadedFile($tmpPath, 'sound.wav', 'audio/wav', null, true);

        $track = $this->buildService()->uploadTrack($this->fakeCustomer(), $file);
        self::assertSame('audio/wav', $track->getMimeType());
        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    public function testUploadAcceptsFlac(): void
    {
        $tmpPath = $this->makeTempAudioFile('flac');
        $file = new UploadedFile($tmpPath, 'lossless.flac', 'audio/flac', null, true);

        $track = $this->buildService()->uploadTrack($this->fakeCustomer(), $file);
        self::assertSame('audio/flac', $track->getMimeType());
        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    public function testUploadAcceptsMp4Audio(): void
    {
        $tmpPath = $this->makeTempAudioFile('m4a');
        $file = new UploadedFile($tmpPath, 'audio.m4a', 'audio/mp4', null, true);

        $track = $this->buildService()->uploadTrack($this->fakeCustomer(), $file);
        self::assertSame('audio/mp4', $track->getMimeType());
        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    public function testUploadRejectsVideoMp4(): void
    {
        $tmpPath = $this->makeTempAudioFile('mp4');
        $file = new UploadedFile($tmpPath, 'video.mp4', 'video/mp4', null, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->buildService()->uploadTrack($this->fakeCustomer(), $file);

        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    public function testUploadRejectsExecutable(): void
    {
        $tmpPath = $this->makeTempAudioFile('exe');
        $file = new UploadedFile($tmpPath, 'malware.exe', 'application/octet-stream', null, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->buildService()->uploadTrack($this->fakeCustomer(), $file);

        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    public function testUploadRejectsPhpFile(): void
    {
        $tmpPath = $this->makeTempAudioFile('php');
        $file = new UploadedFile($tmpPath, 'shell.php', 'text/x-php', null, true);

        $this->expectException(\InvalidArgumentException::class);
        $this->buildService()->uploadTrack($this->fakeCustomer(), $file);

        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    public function testUploadEnforcesTrackQuota(): void
    {
        $quota = $this->createStub(MusicbotQuotaServiceInterface::class);
        $quota->method('assertCanUploadTrack')
            ->willThrowException(new MusicbotQuotaExceededException('Track limit reached'));

        $service = $this->buildService($quota);
        $customer = $this->fakeCustomer();

        $tmpPath = $this->makeTempAudioFile('mp3');
        $file = new UploadedFile($tmpPath, 'song.mp3', 'audio/mpeg', null, true);

        $this->expectException(MusicbotQuotaExceededException::class);
        $this->expectExceptionMessage('Track limit reached');
        $service->uploadTrack($customer, $file);

        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    public function testUploadEnforcesFileSizeQuota(): void
    {
        $quota = $this->createStub(MusicbotQuotaServiceInterface::class);
        $quota->method('assertCanUploadTrack')
            ->willThrowException(new MusicbotQuotaExceededException('Upload size exceeds limit'));

        $service = $this->buildService($quota);

        $tmpPath = $this->makeTempAudioFile('mp3');
        $file = new UploadedFile($tmpPath, 'big.mp3', 'audio/mpeg', null, true);

        $this->expectException(MusicbotQuotaExceededException::class);
        $this->expectExceptionMessage('Upload size exceeds limit');
        $service->uploadTrack($this->fakeCustomer(), $file);

        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    public function testAddWebradioTrackStoresStreamUrl(): void
    {
        $track = $this->buildService()->addWebradioTrack($this->fakeCustomer(), 'Bayern 3', 'https://stream.br.de/bayern3/mp3/128/stream.mp3');

        self::assertSame('Bayern 3', $track->getTitle());
        self::assertSame('webradio', $track->getSourceType()->value);
        self::assertSame('https://stream.br.de/bayern3/mp3/128/stream.mp3', $track->getMetadata()['stream_url']);
    }

    public function testAddWebradioTrackRejectsEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->buildService()->addWebradioTrack($this->fakeCustomer(), '', 'https://stream.example.com/live.mp3');
    }

    public function testAddWebradioTrackRejectsLocalhost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->buildService()->addWebradioTrack($this->fakeCustomer(), 'Local', 'http://localhost:8000/stream');
    }

    public function testAddWebradioTrackRejectsPrivateIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->buildService()->addWebradioTrack($this->fakeCustomer(), 'Internal', 'http://192.168.1.100/live.mp3');
    }

    public function testAddYoutubeTrackStoresUrl(): void
    {
        $track = $this->buildService()->addYoutubeTrack($this->fakeCustomer(), 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Never Gonna Give You Up');

        self::assertSame('Never Gonna Give You Up', $track->getTitle());
        self::assertSame('youtube', $track->getSourceType()->value);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $track->getMetadata()['youtube_url']);
    }

    public function testAddYoutubeTrackDerivesTitle(): void
    {
        $track = $this->buildService()->addYoutubeTrack($this->fakeCustomer(), 'https://youtu.be/dQw4w9WgXcQ');

        self::assertStringContainsString('dQw4w9WgXcQ', $track->getTitle());
    }

    public function testAddYoutubeTrackRejectsNonYoutubeDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->buildService()->addYoutubeTrack($this->fakeCustomer(), 'https://evil.com/watch?v=abc');
    }

    public function testAddYoutubeTrackRejectsFileScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->buildService()->addYoutubeTrack($this->fakeCustomer(), 'file:///etc/passwd');
    }
}
