<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\WebBackupAdapter;
use App\Module\Core\Application\Backup\BackupAdapterRegistry;
use App\Module\Core\Application\Backup\BackupAgent;
use App\Module\Core\Application\Backup\BackupPlan;
use App\Module\Core\Application\Backup\BackupRun;
use App\Module\Core\Application\Backup\BackupStorageTarget;
use App\Module\Core\Application\Backup\BackupTargetValidator;
use App\Module\Core\Application\Backup\BackupTargetWriterRegistry;
use App\Module\Core\Application\Backup\RetentionPolicy;
use App\Module\Core\Application\Backup\RetentionPruner;
use App\Module\Core\Application\Backup\Target\LocalBackupTargetWriter;
use PHPUnit\Framework\TestCase;

final class BackupAgentTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/backup-agent-test-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspace)) {
            exec('rm -rf '.escapeshellarg($this->workspace));
        }
    }

    public function testWebBackupRunAndRestoreDryRunEndToEnd(): void
    {
        $sourceDir = $this->workspace.'/web-root';
        $backupDir = $this->workspace.'/backups';
        mkdir($sourceDir, 0775, true);
        file_put_contents($sourceDir.'/index.html', '<h1>ok</h1>');

        $agent = new BackupAgent(
            new BackupTargetValidator(),
            new BackupTargetWriterRegistry([new LocalBackupTargetWriter()]),
            new BackupAdapterRegistry([new WebBackupAdapter()]),
        );

        $plan = new BackupPlan(
            'plan-web-1',
            'web',
            $sourceDir,
            new BackupStorageTarget('local', 'Local NAS', ['path' => $backupDir]),
            new RetentionPolicy(7, 30),
            '0 3 * * *',
            'UTC',
            ['compression' => 'gzip'],
        );

        $run = $agent->run($plan, 'idem-web-1');

        self::assertSame('succeeded', $run->status());
        self::assertFileExists($run->archivePath());
        self::assertNotSame('', $run->checksumSha256());

        $restoreReport = $agent->restore($plan, $run->archivePath(), true);
        self::assertTrue($restoreReport->dryRun());
        self::assertTrue($restoreReport->success());
    }

    public function testRetentionPruneRemovesOldRunsByCountAndAge(): void
    {
        $pruner = new RetentionPruner();
        $now = new \DateTimeImmutable('2026-02-01 12:00:00');

        $runs = [
            new BackupRun('1', 'plan', 'succeeded', '/a', 100, 'a', $now->modify('-40 days')),
            new BackupRun('2', 'plan', 'succeeded', '/b', 100, 'b', $now->modify('-20 days')),
            new BackupRun('3', 'plan', 'succeeded', '/c', 100, 'c', $now->modify('-10 days')),
            new BackupRun('4', 'plan', 'succeeded', '/d', 100, 'd', $now->modify('-1 days')),
        ];

        $toDelete = $pruner->prune($runs, new RetentionPolicy(2, 30), $now);

        self::assertCount(2, $toDelete);
        self::assertSame('2', $toDelete[0]->runId());
        self::assertSame('1', $toDelete[1]->runId());
    }

    public function testRetentionPruneAuditDeletesOnlySafePaths(): void
    {
        $oldFile = $this->workspace.'/old.tar';
        file_put_contents($oldFile, 'backup');

        $runs = [
            new BackupRun('1', 'plan', 'succeeded', $oldFile, 100, 'a', new \DateTimeImmutable('-60 days')),
            new BackupRun('2', 'plan', 'succeeded', 'https://remote.example/old.tar', 100, 'b', new \DateTimeImmutable('-50 days')),
        ];

        $audit = (new RetentionPruner())->pruneWithAudit($runs, new RetentionPolicy(0, 1), new \DateTimeImmutable(), $this->workspace);

        self::assertCount(2, $audit['pruned']);
        self::assertSame([$oldFile], $audit['deleted']);
        self::assertNotEmpty($audit['skipped']);
        self::assertFileDoesNotExist($oldFile);
    }

    public function testRejectsInvalidTargetValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $validator = new BackupTargetValidator();
        $validator->validate(new BackupStorageTarget('webdav', 'Bad', ['url' => 'http://insecure.invalid']));
    }

    public function testRejectsSchemeRelativeAndPrivateWebdavTarget(): void
    {
        $validator = new BackupTargetValidator();

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate(new BackupStorageTarget('webdav', 'Bad', ['url' => '//127.0.0.1/remote.php/dav']));
    }

    public function testRestoreRejectsArchiveTraversalEntries(): void
    {
        $source = $this->workspace.'/source';
        mkdir($source, 0775, true);
        file_put_contents($source.'/../evil.txt', 'nope');

        $archivePath = $this->workspace.'/traversal.tar';
        $archive = new \PharData($archivePath);
        $archive->addFromString('../evil.txt', 'x');

        $report = (new WebBackupAdapter())->restore($this->workspace.'/restore', $archivePath, true);
        self::assertFalse($report->success());
        self::assertStringContainsString('validation failed', strtolower($report->message()));
    }

    public function testRestoreRejectsArchiveSymlinkEntries(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symlink support not available.');
        }

        $sourceDir = $this->workspace.'/symlink-src';
        mkdir($sourceDir, 0775, true);
        file_put_contents($sourceDir.'/real.txt', 'ok');
        @symlink('real.txt', $sourceDir.'/link.txt');

        $archivePath = $this->workspace.'/symlink.tar';
        exec(sprintf('tar -cf %s -C %s .', escapeshellarg($archivePath), escapeshellarg($sourceDir)));

        $report = (new WebBackupAdapter())->restore($this->workspace.'/restore-symlink', $archivePath, true);
        self::assertFalse($report->success());
        self::assertStringContainsString('symlink', strtolower($report->message()));
    }
}
