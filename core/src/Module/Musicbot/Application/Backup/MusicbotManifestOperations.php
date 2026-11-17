<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application\Backup;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Application\Backup\Adapter\RestoreReport;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPlatform;
use App\Repository\MusicbotInstanceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: MusicbotManifestOperationsInterface::class)]
final class MusicbotManifestOperations implements MusicbotManifestOperationsInterface
{
    public function __construct(
        private readonly MusicbotInstanceRepositoryInterface $instances,
        private readonly MusicbotBackupService $backups,
        private readonly MusicbotRestoreService $restores,
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotBackupDataProvider $dataProvider,
    ) {
    }

    #[\Override]
    public function exportManifest(string $resourceId): string
    {
        $instance = $this->instance($resourceId);

        return $this->backups->serializeToJson($this->backups->createBackup($instance, new MusicbotBackupOptions()));
    }

    #[\Override]
    public function restoreManifest(string $resourceId, string $json, bool $dryRun): RestoreReport
    {
        try {
            $instance = $this->instance($resourceId);
            $manifest = $this->backups->deserializeFromJson($json);
            $this->backups->validateManifest($manifest);
            if ($manifest->customerId !== (string) $instance->getCustomer()->getId()) {
                throw new \InvalidArgumentException('Musicbot backup belongs to a different customer.');
            }
            if ($manifest->instanceId !== (string) $instance->getId()) {
                throw new \InvalidArgumentException('Musicbot backup belongs to a different instance.');
            }
            if (!$this->backups->verifyChecksum($manifest)) {
                throw new \InvalidArgumentException('Musicbot backup checksum does not match.');
            }
            $unsupported = array_diff(array_keys($manifest->data), ['instance', 'connections', 'playlists', 'radio_stations', 'autodj', 'plugins']);
            if ($unsupported !== []) {
                throw new \InvalidArgumentException('The configuration restore cannot restore sections: '.implode(', ', $unsupported).'.');
            }
            $this->validateConnectionCredentials($instance, $manifest);
            if ($dryRun) {
                $report = $this->restores->restore($instance, $manifest, true);
            } else {
                $report = $this->entityManager->wrapInTransaction(function () use ($instance, $manifest): MusicbotRestoreReport {
                    $result = $this->restores->restore($instance, $manifest, false);
                    if (!$result->success) {
                        throw new \RuntimeException($result->error ?? 'Musicbot restore failed.');
                    }

                    return $result;
                });
            }
            if (!$report instanceof MusicbotRestoreReport) {
                throw new \RuntimeException('Musicbot restore did not produce a result.');
            }

            $message = $report->error ?? ($dryRun ? 'Musicbot configuration restore validated; no data changed.' : 'Musicbot configuration restored.');
            $details = array_merge($report->warnings, $report->missing);
            if ($details !== []) {
                $message .= ' Incomplete items: '.implode('; ', $details);
            }

            return new RestoreReport($dryRun, $report->success, $message);
        } catch (\Throwable $e) {
            return new RestoreReport($dryRun, false, $e->getMessage());
        }
    }

    private function instance(string $resourceId): MusicbotInstance
    {
        $id = filter_var($resourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new \InvalidArgumentException('Invalid Musicbot instance identifier.');
        }
        $instance = $this->instances->findById($id);
        if ($instance === null) {
            throw new \InvalidArgumentException('Musicbot instance not found.');
        }

        return $instance;
    }

    private function validateConnectionCredentials(MusicbotInstance $instance, MusicbotBackupManifest $manifest): void
    {
        $connections = JsonArray::fromValue($manifest->data['connections'] ?? null);
        if ($connections === null) {
            return;
        }
        $existingPlatforms = [];
        foreach ($this->dataProvider->getConnections($instance) as $connection) {
            $existingPlatforms[$connection->getPlatform()->value] = true;
        }
        foreach (array_keys($connections) as $index) {
            $connection = JsonArray::fromValue($connections[$index]);
            if ($connection === null) {
                continue;
            }
            $platform = MusicbotPlatform::tryFrom(ScalarValue::text($connection['platform'] ?? ''));
            $secrets = JsonArray::fromValue($connection['secret_config'] ?? null);
            // Customer manifests replace each populated secret with this marker.
            if ($platform !== null && !isset($existingPlatforms[$platform->value]) && $secrets !== null && in_array('********', $secrets, true)) {
                throw new \InvalidArgumentException('Musicbot connection credentials required: configure credentials for '.$platform->value.' before restoring this configuration backup. No restore was performed.');
            }
        }
    }
}
