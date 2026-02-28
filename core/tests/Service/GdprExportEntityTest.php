<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\GdprExport;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\GdprExportStatus;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class GdprExportEntityTest extends TestCase
{
    public function testPendingExportRunsAndCanBeMarkedReady(): void
    {
        $user = new User('customer@example.test', UserType::Customer);
        $export = GdprExport::createPending($user, 'pending.zip');

        self::assertSame(GdprExportStatus::Pending, $export->getStatus());

        $export->markRunning();
        self::assertSame(GdprExportStatus::Running, $export->getStatus());

        $export->markReady(
            'ready.zip',
            1024,
            ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'],
            (new \DateTimeImmutable())->modify('+7 days')
        );

        self::assertSame(GdprExportStatus::Ready, $export->getStatus());
        self::assertSame('ready.zip', $export->getFileName());
        self::assertSame(1024, $export->getFileSize());
    }

    public function testPendingCannotBeMarkedReadyDirectly(): void
    {
        $this->expectException(\LogicException::class);

        $user = new User('customer@example.test', UserType::Customer);
        $export = GdprExport::createPending($user, 'pending.zip');
        $export->markReady(
            'ready.zip',
            1024,
            ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'],
            (new \DateTimeImmutable())->modify('+7 days')
        );
    }

    public function testDownloadTokenIsOneTimeAndExpires(): void
    {
        $user = new User('customer@example.test', UserType::Customer);
        $export = GdprExport::createPending($user, 'pending.zip');
        $token = $export->issueDownloadToken(new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));

        self::assertTrue($export->consumeValidDownloadToken($token, new \DateTimeImmutable('2026-01-01T10:10:00+00:00')));
        self::assertFalse($export->consumeValidDownloadToken($token, new \DateTimeImmutable('2026-01-01T10:11:00+00:00')));

        $token = $export->issueDownloadToken(new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));
        self::assertFalse($export->consumeValidDownloadToken($token, new \DateTimeImmutable('2026-01-01T11:00:00+00:00')));
    }
}
