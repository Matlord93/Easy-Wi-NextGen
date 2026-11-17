<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\WebBackupAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class WebBackupRestoreConfinementTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/web-restore-confinement-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workspace);
    }

    public function testExistingFileSymlinkCannotOverwriteOutsideFile(): void
    {
        mkdir($this->workspace.'/target');
        file_put_contents($this->workspace.'/outside.txt', 'outside-original');
        self::assertTrue(symlink($this->workspace.'/outside.txt', $this->workspace.'/target/index.txt'));
        $archive = $this->archive(['index.txt' => 'archive-data']);

        $adapter = new WebBackupAdapter();
        self::assertFalse($adapter->restore($this->workspace.'/target', $archive, true)->success());
        $report = $adapter->restore($this->workspace.'/target', $archive);

        self::assertFalse($report->success());
        self::assertSame('outside-original', file_get_contents($this->workspace.'/outside.txt'));
        self::assertTrue(is_link($this->workspace.'/target/index.txt'));
    }

    public function testSymlinkInRestoreRootParentCannotRedirectWrites(): void
    {
        mkdir($this->workspace.'/outside');
        mkdir($this->workspace.'/outside/site');
        file_put_contents($this->workspace.'/outside/site/index.txt', 'outside-original');
        self::assertTrue(symlink($this->workspace.'/outside', $this->workspace.'/alias'));
        $archive = $this->archive(['index.txt' => 'archive-data']);

        $report = (new WebBackupAdapter())->restore($this->workspace.'/alias/site', $archive);

        self::assertFalse($report->success());
        self::assertSame('outside-original', file_get_contents($this->workspace.'/outside/site/index.txt'));
    }

    public function testHardlinkArchiveIsRejectedBeforeExtraction(): void
    {
        mkdir($this->workspace.'/source');
        file_put_contents($this->workspace.'/source/first.txt', 'archive-data');
        self::assertTrue(link($this->workspace.'/source/first.txt', $this->workspace.'/source/second.txt'));
        $archive = $this->workspace.'/hardlinks.tar';
        (new Process(['tar', '-cf', $archive, '-C', $this->workspace.'/source', 'first.txt', 'second.txt']))->mustRun();

        $report = (new WebBackupAdapter())->restore($this->workspace.'/target', $archive, true);

        self::assertFalse($report->success());
        self::assertDirectoryDoesNotExist($this->workspace.'/target');
    }

    public function testOrdinaryNestedFilesRestoreAndDryRunDoesNotWrite(): void
    {
        $archive = $this->archive(['sub/index.txt' => "archive-data\0binary"]);
        $adapter = new WebBackupAdapter();
        self::assertTrue($adapter->restore($this->workspace.'/target', $archive, true)->success());
        self::assertDirectoryDoesNotExist($this->workspace.'/target');

        self::assertTrue($adapter->restore($this->workspace.'/target', $archive)->success());
        self::assertSame("archive-data\0binary", file_get_contents($this->workspace.'/target/sub/index.txt'));
    }

    public function testFileInTargetRootPathFailsWithoutWarningsOrChanges(): void
    {
        file_put_contents($this->workspace.'/blocker', 'original');
        $report = (new WebBackupAdapter())->restore($this->workspace.'/blocker/site', $this->archive(['index.txt' => 'archive-data']));

        self::assertFalse($report->success());
        self::assertSame('original', file_get_contents($this->workspace.'/blocker'));
    }

    /** @param array<string, string> $files */
    private function archive(array $files): string
    {
        $path = $this->workspace.'/backup.tar';
        $archive = new \PharData($path);
        foreach ($files as $name => $contents) {
            $archive->addFromString($name, $contents);
        }

        return $path;
    }
}
