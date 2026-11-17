<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Resource;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Backup\BackupTarReader;
use App\Module\Core\Application\Backup\BackupFileChecksum;
use App\Module\Core\Application\Backup\BackupOperationHeartbeat;

/** Two regular outer members; the opaque inner archive is validated by the Agent. */
final class ModuleBackupBundle
{
    private const int MAX_MANIFEST_BYTES = 16777216;

    public function __construct(private readonly SecretsCrypto $crypto, private readonly BackupTarReader $tar = new BackupTarReader(), private readonly ?BackupOperationHeartbeat $heartbeat = null)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function seal(ManagedBackupResource $resource, string $directory, array $metadata): void
    {
        $payload = $directory.'/payload.tar.gz';
        $checksum = BackupFileChecksum::sha256($payload, $this->heartbeat);
        if (filesize($payload) === 0) {
            throw new \RuntimeException('Agent did not produce a readable backup payload.');
        }
        $manifest = json_encode([
            'version' => 1, 'module' => $resource->descriptor['module'], 'resource_id' => $resource->descriptor['resource_id'],
            'customer_id' => $resource->customerId, 'identity' => $resource->identity(), 'sha256' => $checksum,
            'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR);
        $record = json_encode(['version' => 1, 'record' => $this->crypto->encrypt($manifest)], JSON_THROW_ON_ERROR);
        if (strlen($record) > self::MAX_MANIFEST_BYTES || file_put_contents($directory.'/resource.json', $record) !== strlen($record)) {
            throw new \RuntimeException('Unable to write bounded resource backup manifest.');
        }
        chmod($directory.'/resource.json', 0600);
        chmod($payload, 0600);
    }

    /** @return array<string, mixed> Metadata authenticated in private staging before any live restore. */
    public function open(ManagedBackupResource $resource, string $archivePath, string $directory): array
    {
        if (!is_file($archivePath) || is_link($archivePath) || str_contains($archivePath, '://')) {
            throw new \InvalidArgumentException('A regular local module archive is required.');
        }
        $checksums = $this->tar->extractFiles($archivePath, $directory, ['resource.json' => self::MAX_MANIFEST_BYTES, 'payload.tar.gz' => null]);
        $json = file_get_contents($directory.'/resource.json');
        if ($json === false) {
            throw new \RuntimeException('Unable to read staged module backup manifest.');
        }
        $envelope = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || ($envelope['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported module manifest.');
        }
        $record = $envelope['record'] ?? null;
        if (!is_string($record)) {
            throw new \InvalidArgumentException('Invalid encrypted module manifest.');
        }
        $manifest = json_decode($this->crypto->decrypt($record), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1
            || ($manifest['module'] ?? null) !== $resource->descriptor['module']
            || ($manifest['resource_id'] ?? null) !== $resource->descriptor['resource_id']
            || ($manifest['customer_id'] ?? null) !== $resource->customerId
            || ($manifest['identity'] ?? null) !== $resource->identity()) {
            throw new \InvalidArgumentException('Backup manifest is not bound to this resource, customer and configured destination.');
        }
        $expectedChecksum = $manifest['sha256'] ?? null;
        $metadata = $manifest['metadata'] ?? null;
        if (!is_string($expectedChecksum) || !preg_match('/^[0-9a-f]{64}$/D', $expectedChecksum) || !is_array($metadata)) {
            throw new \InvalidArgumentException('Invalid module payload checksum or metadata.');
        }
        foreach (array_keys($metadata) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Invalid module metadata keys.');
            }
        }
        $checksum = $checksums['payload.tar.gz'];
        if (!hash_equals($expectedChecksum, $checksum)) {
            throw new \InvalidArgumentException('Module payload checksum does not match its authenticated manifest.');
        }
        chmod($directory.'/payload.tar.gz', 0600);
        /** @var array<string, mixed> $metadata Validated string keys above. */
        return $metadata;
    }
}
