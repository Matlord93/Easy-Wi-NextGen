<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup;

use App\Module\Core\Application\Backup\Adapter\RestoreReport;

final class BackupAgent
{
    public function __construct(
        private readonly BackupTargetValidator $targetValidator,
        private readonly BackupTargetWriterRegistry $targetWriterRegistry,
        private readonly BackupAdapterRegistry $adapterRegistry,
    ) {
    }

    public function run(BackupPlan $plan, ?string $idempotencyKey = null): BackupRun
    {
        $this->targetValidator->validate($plan->target());

        $adapter = $this->adapterRegistry->forModule($plan->module());
        $snapshot = $adapter->snapshot($plan->resourceId());

        $runId = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : bin2hex(random_bytes(12));
        $tempArchive = sys_get_temp_dir().'/'.$plan->id().'-'.$runId.'.tar';

        $this->archivePath($snapshot->sourcePath(), $tempArchive);

        $finalArchivePath = $tempArchive;
        $compression = strtolower((string) ($plan->options()['compression'] ?? 'none'));
        if ($compression === 'gzip') {
            $finalArchivePath = $tempArchive.'.gz';
            $this->gzipFile($tempArchive, $finalArchivePath);
        }

        if (($plan->options()['encrypt'] ?? false) === true) {
            $key = (string) ($plan->options()['encryption_key'] ?? '');
            if ($key === '') {
                throw new \InvalidArgumentException('Encryption was enabled but encryption_key is empty.');
            }
            $encryptedPath = $finalArchivePath.'.enc';
            $this->encryptFile($finalArchivePath, $encryptedPath, $key);
            $finalArchivePath = $encryptedPath;
        }

        $checksum = hash_file('sha256', $finalArchivePath);
        if (!is_string($checksum) || $checksum === '') {
            throw new \RuntimeException('Failed to generate backup checksum.');
        }

        $destination = $this->targetWriterRegistry->write($plan->target(), basename($finalArchivePath), $finalArchivePath);
        $size = filesize($finalArchivePath) ?: 0;

        return new BackupRun($runId, $plan->id(), 'succeeded', $destination, $size, $checksum);
    }

    public function restore(BackupPlan $plan, string $archivePath, bool $dryRun = false): RestoreReport
    {
        $adapter = $this->adapterRegistry->forModule($plan->module());

        return $adapter->restore($plan->resourceId(), $archivePath, $dryRun);
    }

    private function archivePath(string $sourcePath, string $archivePath): void
    {
        $source = rtrim($sourcePath, '/');
        if (!is_dir($source)) {
            throw new \InvalidArgumentException('Backup source path does not exist: '.$source);
        }

        $archive = new \PharData($archivePath);
        $archive->buildFromDirectory($source);
    }

    private function gzipFile(string $inputPath, string $outputPath): void
    {
        $input = fopen($inputPath, 'rb');
        $output = gzopen($outputPath, 'wb9');

        if (!is_resource($input) || $output === false) {
            throw new \RuntimeException('Failed to open compression streams.');
        }

        while (!feof($input)) {
            $chunk = fread($input, 1048576);
            if ($chunk === false) {
                throw new \RuntimeException('Failed to read archive chunk during compression.');
            }
            gzwrite($output, $chunk);
        }

        fclose($input);
        gzclose($output);
    }

    private function encryptFile(string $inputPath, string $outputPath, string $key): void
    {
        $plaintext = file_get_contents($inputPath);
        if (!is_string($plaintext)) {
            throw new \RuntimeException('Failed to read archive for encryption.');
        }

        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Backup encryption failed.');
        }

        file_put_contents($outputPath, $iv.$ciphertext);
    }
}
