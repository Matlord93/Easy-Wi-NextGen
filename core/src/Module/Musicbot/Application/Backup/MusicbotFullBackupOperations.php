<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Config\JsonArray;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use App\Module\Core\Application\Backup\BackupTemporaryDirectory;
use App\Module\Core\Application\Backup\ModuleArchiveGatewayInterface;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Repository\MusicbotInstanceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotFullBackupOperations
{
    private const EXCLUDES = ['runtime', 'tmp', 'logs', 'control_sock', 'control.sock', 'musicbot.sock', 'musicbot.pid', 'pid', 'run'];

    public function __construct(
        private readonly MusicbotInstanceRepositoryInterface $instances,
        private readonly MusicbotFullState $state,
        private readonly ModuleArchiveGatewayInterface $gateway,
        private readonly SecretsCryptoInterface $crypto,
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotArchiveStreams $streams = new MusicbotArchiveStreams(),
    ) {
    }

    public function snapshot(string $resourceId, string $directory): void
    {
        $instance = $this->instance($resourceId);
        $descriptor = $this->descriptor($instance);
        $this->entityManager->flush();
        $data = $this->state->export($instance);
        $this->state->validate($instance, $data);
        $this->validateCredentials($data);
        $this->gateway->snapshot($instance->getNode(), (string) $instance->getCustomer()->getId(), $descriptor, $directory.'/assets.tar.gz');
        $this->state->validateAssets($instance, $data, $directory.'/assets.tar.gz');
        $payload = ['format' => 1, 'binding' => $this->binding($instance), 'state' => $data,
            'assets_sha256' => $this->hash($directory.'/assets.tar.gz'), 'excludes' => self::EXCLUDES];
        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($plaintext) > 67_108_864) {
            throw new \RuntimeException('Full Musicbot metadata exceeds the supported size.');
        }
        $json = json_encode(['format' => 'easywi-musicbot-full-v1', 'encrypted_state' => $this->crypto->encrypt($plaintext)], JSON_THROW_ON_ERROR);
        if (file_put_contents($directory.'/full.json', $json) !== strlen($json)) {
            throw new \RuntimeException('Cannot write encrypted full Musicbot metadata.');
        }
        chmod($directory.'/full.json', 0600);
    }

    public function restore(string $resourceId, string $json, string $assets, bool $dryRun): RestoreReport
    {
        $recovery = null;
        $preserveRecovery = false;
        try {
            $instance = $this->instance($resourceId);
            $envelope = JsonMap::decode($json) ?? [];
            $encryptedState = $envelope['encrypted_state'] ?? null;
            if (($envelope['format'] ?? null) !== 'easywi-musicbot-full-v1' || !is_string($encryptedState)) {
                throw new \InvalidArgumentException('Invalid full Musicbot encrypted metadata.');
            }
            $payload = JsonMap::decode($this->crypto->decrypt($encryptedState)) ?? [];
            if (($payload['format'] ?? null) !== 1 || ($payload['binding'] ?? null) !== $this->binding($instance)
                || ($payload['excludes'] ?? null) !== self::EXCLUDES) {
                throw new \InvalidArgumentException('Full Musicbot backup belongs to another instance, customer, node or managed directory.');
            }
            $checksum = $payload['assets_sha256'] ?? null;
            if (!is_string($checksum) || !hash_equals($checksum, $this->hash($assets))) {
                throw new \InvalidArgumentException('Full Musicbot media archive checksum does not match.');
            }
            $data = JsonMap::fromValue($payload['state'] ?? null);
            if ($data === null) {
                throw new \InvalidArgumentException('Missing full Musicbot database state.');
            }
            $this->state->validate($instance, $data);
            $this->validateCredentials($data);
            $this->state->validateAssets($instance, $data, $assets);
            $descriptor = $this->descriptor($instance);
            $customer = (string) $instance->getCustomer()->getId();
            $preflight = $this->gateway->restore($instance->getNode(), $customer, $descriptor, $assets, true);
            if (!$preflight->success()) {
                throw new \RuntimeException('Musicbot agent preflight failed: '.$preflight->message());
            }
            if ($dryRun) {
                return new RestoreReport(true, true, 'Full Musicbot metadata and media validated; no live data changed.');
            }
            $recovery = BackupTemporaryDirectory::create('easywi-musicbot-recovery-');
            $this->gateway->snapshot($instance->getNode(), $customer, $descriptor, $recovery.'/assets.tar.gz');
            try {
                $this->entityManager->wrapInTransaction(function () use ($instance, $data, $descriptor, $customer, $assets): bool {
                    $this->state->apply($instance, $data);
                    $result = $this->gateway->restore($instance->getNode(), $customer, $descriptor, $assets, false);
                    if (!$result->success()) {
                        throw new \RuntimeException('Musicbot media restore failed: '.$result->message());
                    }
                    return true;
                });
            } catch (\Throwable $error) {
                // Also recover after a failed apply: an interrupted response cannot prove no remote mutation.
                try {
                    $rollback = $this->gateway->restore($instance->getNode(), $customer, $descriptor, $recovery.'/assets.tar.gz', false);
                } catch (\Throwable $rollbackError) {
                    $preserveRecovery = true;
                    throw new \RuntimeException('Musicbot restore failed; media rollback could not complete. Recovery archive retained at '.$recovery.'/assets.tar.gz.', 0, $rollbackError);
                }
                if (!$rollback->success()) {
                    $preserveRecovery = true;
                    throw new \RuntimeException('Musicbot restore failed; media rollback also failed. Recovery archive retained at '.$recovery.'/assets.tar.gz. '.$rollback->message(), 0, $error);
                }
                throw $error;
            }
            $this->entityManager->clear();

            return new RestoreReport(false, true, 'Full Musicbot metadata, credentials and media restored. Transient processes and sockets are reinitialized by the managed service.');
        } catch (\Throwable $error) {
            return new RestoreReport($dryRun, false, $error->getMessage());
        } finally {
            if ($recovery !== null && !$preserveRecovery) {
                BackupTemporaryDirectory::remove($recovery, 'easywi-musicbot-recovery-');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateCredentials(array $data): void
    {
        $tables = JsonMap::fromValue($data['tables'] ?? null) ?? [];
        $connections = JsonArray::orEmpty($tables['musicbot_connections'] ?? null);
        foreach (array_keys($connections) as $index) {
            $row = JsonMap::fromValue($connections[$index]) ?? [];
            $encoded = $row['secret_config'] ?? null;
            if (!is_string($encoded)) {
                throw new \InvalidArgumentException('Invalid Musicbot connection credential storage.');
            }
            $secrets = JsonMap::decode($encoded);
            if ($secrets === null) {
                throw new \InvalidArgumentException('Invalid Musicbot connection credential configuration.');
            }
            foreach (array_keys($secrets) as $key) {
                $secret = $secrets[$key];
                if (!is_string($secret) || $secret === '********') {
                    throw new \InvalidArgumentException('Full Musicbot recovery requires recoverable connection credentials.');
                }
                if (str_starts_with($secret, 'v1:')) {
                    try {
                        $this->crypto->decrypt($secret);
                    } catch (\Throwable $error) {
                        throw new \InvalidArgumentException('Musicbot connection credentials cannot be decrypted with the configured installation keys.', 0, $error);
                    }
                }
            }
        }
    }

    private function instance(string $resourceId): MusicbotInstance
    {
        $id = filter_var($resourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $instance = $id !== false ? $this->instances->findById($id) : null;
        if ($instance === null) {
            throw new \InvalidArgumentException('Musicbot instance not found.');
        }

        return $instance;
    }

    /** @return array<string, scalar|array|null> */
    private function descriptor(MusicbotInstance $instance): array
    {
        return ['module' => 'musicbot', 'resource_id' => (string) $instance->getId(),
            'path' => $instance->getInstallPath(), 'service' => $instance->getServiceName(), 'exclude' => self::EXCLUDES];
    }

    /** @return array{instance: string, customer: string, node: string, path: string, service: string} */
    private function binding(MusicbotInstance $instance): array
    {
        return ['instance' => (string) $instance->getId(), 'customer' => (string) $instance->getCustomer()->getId(),
            'node' => $instance->getNode()->getId(), 'path' => $instance->getInstallPath(), 'service' => $instance->getServiceName()];
    }

    private function hash(string $path): string
    {
        return $this->streams->checksum($path);
    }
}
