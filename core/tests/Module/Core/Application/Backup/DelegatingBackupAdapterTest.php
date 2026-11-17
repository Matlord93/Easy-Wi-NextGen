<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\BackupSnapshot;
use App\Module\Core\Application\Backup\Adapter\DbBackupAdapter;
use App\Module\Core\Application\Backup\Adapter\GameBackupAdapter;
use App\Module\Core\Application\Backup\Adapter\MailBackupAdapter;
use App\Module\Core\Application\Backup\Adapter\ModuleBackupOperationsInterface;
use App\Module\Core\Application\Backup\Adapter\ModuleBackupOperationsRegistry;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use App\Module\Core\Application\Backup\Adapter\VoiceBackupAdapter;
use PHPUnit\Framework\TestCase;

final class DelegatingBackupAdapterTest extends TestCase
{
    public function testUnconfiguredModulesNeverReportACompletedRestore(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'module-backup-test-');
        try {
            foreach ([new MailBackupAdapter(), new VoiceBackupAdapter(), new GameBackupAdapter(), new DbBackupAdapter()] as $adapter) {
                $report = $adapter->restore('42', $file);
                self::assertFalse($report->success(), $adapter->module());
                self::assertStringContainsString('No restore was performed', $report->message());
                self::assertFalse($adapter->restore('42', $file, true)->success());
            }
        } finally {
            unlink($file);
        }
    }

    public function testConfiguredProviderReceivesTheExactRestoreOperation(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'module-backup-test-');
        $provider = $this->createMock(ModuleBackupOperationsInterface::class);
        $provider->method('module')->willReturn('mail');
        $report = new RestoreReport(true, true, 'Validated by the mail worker.');
        $provider->expects(self::once())->method('restore')->with('42', $file, true)->willReturn($report);
        try {
            self::assertSame($report, (new MailBackupAdapter(new ModuleBackupOperationsRegistry([$provider])))->restore('42', $file, true));
        } finally {
            unlink($file);
        }
    }

    public function testConfiguredProviderResolvesAnActualSnapshotRatherThanTreatingIdAsPath(): void
    {
        $provider = $this->createMock(ModuleBackupOperationsInterface::class);
        $provider->method('module')->willReturn('voice');
        $snapshot = new BackupSnapshot('/resolved/source/path', 'voice.tar');
        $provider->expects(self::once())->method('snapshot')->with('42')->willReturn($snapshot);
        self::assertSame($snapshot, (new VoiceBackupAdapter(new ModuleBackupOperationsRegistry([$provider])))->snapshot('42'));
    }

    public function testUnconfiguredSnapshotFailsBeforeAnyArchiveCanBeClaimed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No backup operation provider');
        (new MailBackupAdapter())->snapshot('42');
    }

    public function testDuplicateOperationProvidersAreRejected(): void
    {
        $provider = $this->createStub(ModuleBackupOperationsInterface::class);
        $provider->method('module')->willReturn('mail');
        $this->expectException(\InvalidArgumentException::class);
        new ModuleBackupOperationsRegistry([$provider, $provider]);
    }
}
