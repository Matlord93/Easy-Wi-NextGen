<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\BackupTemporaryDirectory;
use App\Module\Core\Application\Backup\Resource\ManagedBackupResource;
use App\Module\Core\Application\Backup\Resource\ModuleBackupBundle;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Agent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ModuleBackupBundleTest extends TestCase
{
    private string $directory;
    private ModuleBackupBundle $bundles;

    protected function setUp(): void
    {
        $this->directory = BackupTemporaryDirectory::create('easywi-backup-snapshot-');
        $this->bundles = new ModuleBackupBundle(new SecretsCrypto('module-backup-test-secret'));
    }

    protected function tearDown(): void
    {
        BackupTemporaryDirectory::remove($this->directory, 'easywi-backup-snapshot-');
    }

    public function testAuthenticatedManifestAndOpaquePayloadRoundTripWithoutExposingMetadata(): void
    {
        $archive = $this->archive();
        self::assertStringNotContainsString('secret-password-hash', file_get_contents($this->directory.'/bundle/resource.json'));
        mkdir($this->directory.'/restore', 0700);
        self::assertSame(['mailbox_password_hash' => 'secret-password-hash'], $this->bundles->open($this->resource(), $archive, $this->directory.'/restore'));
        self::assertSame('opaque-streaming-payload', file_get_contents($this->directory.'/restore/payload.tar.gz'));
    }

    public function testDifferentCustomerCannotUseAnOtherwiseValidArchive(): void
    {
        $archive = $this->archive();
        mkdir($this->directory.'/restore', 0700);
        $this->expectException(\InvalidArgumentException::class);
        $this->bundles->open($this->resource('another-customer'), $archive, $this->directory.'/restore');
    }

    public function testChangedDestinationCannotReplayArchive(): void
    {
        $archive = $this->archive();
        mkdir($this->directory.'/restore', 0700);
        $resource = $this->resource();
        $resource = new ManagedBackupResource($resource->agent, $resource->customerId, ['module' => 'game', 'resource_id' => '42', 'path' => '/home/different', 'service' => 'game-42']);
        $this->expectException(\InvalidArgumentException::class);
        $this->bundles->open($resource, $archive, $this->directory.'/restore');
    }

    public function testChangedPayloadCannotPassAuthenticatedChecksum(): void
    {
        $archive = $this->archive();
        $tar = new \PharData($archive);
        $tar['payload.tar.gz'] = 'tampered';
        unset($tar);
        mkdir($this->directory.'/restore', 0700);
        $this->expectException(\InvalidArgumentException::class);
        $this->bundles->open($this->resource(), $archive, $this->directory.'/restore');
    }

    public function testSymlinkCannotMasqueradeAsPayload(): void
    {
        $archive = $this->archive();
        unlink($this->directory.'/bundle/payload.tar.gz');
        symlink('/etc/passwd', $this->directory.'/bundle/payload.tar.gz');
        unlink($archive);
        (new Process(['tar', '-cf', $archive, '-C', $this->directory.'/bundle', 'resource.json', 'payload.tar.gz']))->mustRun();
        mkdir($this->directory.'/restore', 0700);
        $this->expectException(\InvalidArgumentException::class);
        $this->bundles->open($this->resource(), $archive, $this->directory.'/restore');
    }

    private function archive(): string
    {
        mkdir($this->directory.'/bundle', 0700);
        file_put_contents($this->directory.'/bundle/payload.tar.gz', 'opaque-streaming-payload');
        $this->bundles->seal($this->resource(), $this->directory.'/bundle', ['mailbox_password_hash' => 'secret-password-hash']);
        $archive = $this->directory.'/bundle.tar';
        $tar = new \PharData($archive);
        $tar->buildFromDirectory($this->directory.'/bundle');
        return $archive;
    }

    private function resource(string $customer = 'customer-42'): ManagedBackupResource
    {
        return new ManagedBackupResource(new Agent('node-42', ['key_id' => 'test', 'nonce' => '', 'ciphertext' => '']), $customer,
            ['module' => 'game', 'resource_id' => '42', 'path' => '/home/game-42', 'service' => 'game-42']);
    }
}
