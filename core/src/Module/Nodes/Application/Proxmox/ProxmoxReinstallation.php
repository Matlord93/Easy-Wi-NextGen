<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Doctrine\EntityIdentifier;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Nodes\Application\VmGuestNetworkReport;
use App\Module\Nodes\Application\VmProvisioningSettings;
use App\Module\Nodes\Application\VmRootPassword;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/** @psalm-import-type Reinstallation from ProxmoxTask */
final readonly class ProxmoxReinstallation
{
    private const GIB = 1073741824;
    private const SUBMISSION_CONFIRMATION_SECONDS = 300;
    private const PASSWORD_RETRY_SECONDS = 15;
    private const OBSERVABLE_STEPS = ['restoring_options', 'resizing', 'starting', 'removing'];

    public function __construct(private ProxmoxApiClient $api, private SecretsCryptoInterface $crypto, private EntityManagerInterface $em, private LockFactory $locks, private AuditLoggerInterface $audit)
    {
    }

    /** @return array{digest: string, images: array<int, string>, original: bool}|null */
    public function choices(ProxmoxGuestAssignment $assignment, string $node, int $siteId): ?array
    {
        $cluster = $assignment->getCluster();
        $images = [];
        foreach ($this->em->getRepository(ShopProduct::class)->findBy(['siteId' => $siteId, 'kind' => ShopProductKind::Proxmox, 'isActive' => true], ['name' => 'ASC']) as $product) {
            if (0 < self::templateId($product, $cluster, $node)) {
                $images[EntityIdentifier::require($product->getId())] = $product->getName();
            }
        }
        $original = $this->originalTemplate($assignment);
        if ([] === $images && 0 === $original) {
            return null;
        }
        try {
            $connection = $cluster->connection($this->crypto);
            $config = $this->targetConfiguration($assignment, $node, $connection);
            $disk = self::eligibleTargetDisk($config, $assignment->getVmId());
        } catch (\InvalidArgumentException) {
            return null;
        }

        $originalAvailable = false;
        if ($original > 0) {
            try {
                $this->source($assignment, $connection, $node, 0, 0, $config, $disk);
                $originalAvailable = true;
            } catch (\InvalidArgumentException|ProxmoxApiException) {
                // The original template may have been removed or moved to another node.
            }
        }

        return [] === $images && !$originalAvailable ? null : ['digest' => $config['digest'], 'images' => $images, 'original' => $originalAvailable];
    }

    /** @return Reinstallation */
    public function plan(ProxmoxGuestAssignment $assignment, string $node, int $siteId, int $productId, string $expectedDigest, ?string $installPasswordHash = null): array
    {
        $cluster = $assignment->getCluster();
        $connection = $cluster->connection($this->crypto);
        $config = $this->targetConfiguration($assignment, $node, $connection);
        if ($expectedDigest !== $config['digest']) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        $disk = self::eligibleTargetDisk($config, $assignment->getVmId());
        $this->assertIdle($connection, $node, $assignment->getVmId());
        $this->assertDiskPermissions($connection, $assignment->getVmId(), explode(':', $disk['volume'], 2)[0]);
        if ($installPasswordHash !== null) {
            if (!preg_match(VmRootPassword::HASH_PATTERN, $installPasswordHash)
                || !$this->api->hasRootPasswordPermission($connection, $assignment->getVmId())) {
                throw new \InvalidArgumentException('pve.error.permissions');
            }
        }
        $source = $this->source($assignment, $connection, $node, $siteId, $productId, $config, $disk);

        return [
            'phase' => 'queued', 'assignment_id' => EntityIdentifier::require($assignment->getId()),
            'tag' => $assignment->getIdentityTag(), 'product_id' => $productId, 'digest' => $config['digest'],
            'configuration' => self::configurationFingerprint($config, $disk['device']),
            'original_volume' => $disk['volume'], 'device' => $disk['device'], 'device_options' => $disk['options'], 'disk_gib' => intdiv($disk['size_bytes'], self::GIB),
            'source_volume' => $source['volume'], 'source_digest' => $source['digest'],
        ] + ($installPasswordHash === null ? [] : ['install_password_hash' => $installPasswordHash]);
    }

    public function advance(ProxmoxTask $task): void
    {
        $lock = $this->locks->createLock('proxmox.reinstallation.'.EntityIdentifier::require($task->getId()), 90);
        if (!$lock->acquire()) {
            return;
        }
        try {
            $this->em->refresh($task);
            $plan = $task->getReinstallation();
            if (null === $plan || !$task->isActive() || 'uncertain' === $task->getState()) {
                return;
            }
            if ('submitting' === $task->getState()) {
                if ('setting_password' === $plan['phase']) {
                    $this->retryPassword($task, $plan);
                } elseif (in_array($plan['phase'], self::OBSERVABLE_STEPS, true)) {
                    $this->reconcileSubmission($task, $plan);
                } else {
                    $this->failed($task, 'dispatch_unknown');
                }

                return;
            }
            if (($plan['rollback_requested_at'] ?? $task->getCreatedAt()->getTimestamp()) < time() - 3600) {
                $this->failed($task, 'reinstall_timeout');

                return;
            }
            $assignment = $this->checkedAssignment($task, $plan);
            $connection = $task->getCluster()->connection($this->crypto);
            $node = $task->getNode();
            if (null !== ($upid = $task->getUpid())) {
                $status = $this->api->taskStatus($connection, $node, $upid);
                if ('stopped' !== ($status['status'] ?? null)) {
                    return;
                }
                if ('OK' !== ($status['exitstatus'] ?? null)) {
                    $this->failed($task, 'upstream_failed');

                    return;
                }
            }
            if (isset($plan['rollback_requested_at'])) {
                $this->advanceRollback($task, $assignment, $connection, $plan);
            } else {
                $this->advanceStep($task, $assignment, $connection, $plan);
            }
        } catch (\InvalidArgumentException $exception) {
            $this->failed($task, match ($exception->getMessage()) {
                'pve.error.identity' => 'identity_mismatch',
                'pve.error.permissions' => 'permission_denied',
                default => 'reinstall_configuration_changed',
            });
        } catch (ProxmoxApiException $exception) {
            $phase = $task->getReinstallation()['phase'] ?? null;
            if ('submitting' === $task->getState() && 'setting_password' !== $phase && !in_array($phase, self::OBSERVABLE_STEPS, true)) {
                $this->failed($task, 'dispatch_unknown');
            } elseif (in_array($exception->statusCode, [401, 403], true)) {
                $this->failed($task, 'permission_denied');
            }
        } finally {
            $lock->release();
        }
    }

    public function canConfirmRemoval(ProxmoxTask $task): bool
    {
        $plan = $task->getReinstallation();

        return 'uncertain' === $task->getState() && 'removing' === ($plan['phase'] ?? null) && isset($plan['replacement_volume'], $plan['started_at']);
    }

    public function confirmRemoval(ProxmoxTask $task, User $actor, string $note): void
    {
        if (!$actor->isAdmin() || !$this->canConfirmRemoval($task)) {
            throw new \InvalidArgumentException('pve.error.review_reinstallation');
        }
        $lock = $this->locks->createLock('proxmox.reinstallation.'.EntityIdentifier::require($task->getId()), 90);
        if (!$lock->acquire()) {
            throw new \InvalidArgumentException('pve.error.task_active');
        }
        try {
            $this->em->refresh($task);
            $plan = $task->getReinstallation();
            if (!$this->canConfirmRemoval($task) || null === $plan || !$this->isRemovalConfirmed($task, $plan)) {
                throw new \InvalidArgumentException('pve.error.reinstall_removal_unconfirmed');
            }
            $this->em->getConnection()->transactional(function () use ($task, $actor, $note): void {
                $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
                if (!$this->canConfirmRemoval($task)) {
                    throw new \InvalidArgumentException('pve.error.review_reinstallation');
                }
                $this->finishReinstallation($task, $actor, $note);
            });
        } finally {
            $lock->release();
        }
    }

    public function canRequestRollback(ProxmoxTask $task): bool
    {
        $plan = $task->getReinstallation();

        return 'reinstall' === $task->getAction() && 'uncertain' === $task->getState() && null !== $plan
            && (isset($plan['replacement_volume']) || ('importing' === $plan['phase'] && isset($plan['import_serial'])));
    }

    public function requestRollback(ProxmoxTask $task, User $actor): void
    {
        if (!$actor->isAdmin() || !$this->canRequestRollback($task)) {
            throw new \InvalidArgumentException('pve.error.reinstall_rollback');
        }
        $taskId = EntityIdentifier::require($task->getId());
        $lock = $this->locks->createLock('proxmox.reinstallation.'.$taskId, 90);
        if (!$lock->acquire()) {
            throw new \InvalidArgumentException('pve.error.task_active');
        }
        try {
            $this->em->refresh($task);
            $plan = $task->getReinstallation();
            if (!$this->canRequestRollback($task) || null === $plan) {
                throw new \InvalidArgumentException('pve.error.reinstall_rollback');
            }
            $assignment = $this->checkedAssignment($task, $plan);
            $state = $this->rollbackConfiguration($assignment, $task->getCluster()->connection($this->crypto), $task->getNode(), $plan);
            $plan['replacement_volume'] = $state['replacement'];
            $this->em->getConnection()->transactional(function () use ($task, $taskId, $actor, $plan): void {
                $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
                if (!$this->canRequestRollback($task)) {
                    throw new \InvalidArgumentException('pve.error.reinstall_rollback');
                }
                $plan['phase'] = 'rollback_queued';
                $plan['rollback_requested_at'] = time();
                unset($plan['install_password_hash']);
                $task->queueReinstallationRollback($plan);
                $this->audit->log($actor, 'proxmox.reinstallation_rollback_requested', ['cluster_id' => $task->getCluster()->getId(), 'vm_id' => $task->getVmId(), 'task_id' => $taskId]);
                $this->em->flush();
            });
        } finally {
            $lock->release();
        }
    }

    /** @param Reinstallation $plan */
    private function checkedAssignment(ProxmoxTask $task, array $plan): ProxmoxGuestAssignment
    {
        $assignment = $this->em->find(ProxmoxGuestAssignment::class, $plan['assignment_id']);
        if (null === $assignment || $assignment->getCustomer()->getId() !== $task->getActorId() || $assignment->getIdentityTag() !== $plan['tag']
            || $assignment->getCluster()->getId() !== $task->getCluster()->getId() || 'qemu' !== $assignment->getKind() || $assignment->getVmId() !== $task->getVmId()) {
            throw new \InvalidArgumentException('pve.error.identity');
        }

        return $assignment;
    }

    /** @param Reinstallation $plan */
    private function advanceRollback(ProxmoxTask $task, ProxmoxGuestAssignment $assignment, ProxmoxConnection $connection, array $plan): void
    {
        $node = $task->getNode();
        $vmId = $task->getVmId();
        $state = $this->rollbackConfiguration($assignment, $connection, $node, $plan);
        if ($state['restored']) {
            $task->finish(false, 'reinstall_rolled_back');
            $this->audit->log(null, 'proxmox.reinstallation_rolled_back', ['cluster_id' => $task->getCluster()->getId(), 'vm_id' => $vmId, 'task_id' => $task->getId()]);
            $this->em->flush();

            return;
        }
        if ('rollback_attaching' === $plan['phase']) {
            throw new \InvalidArgumentException('pve.error.reinstall_rollback');
        }
        if ('running' === $state['power']) {
            if ('rollback_queued' === $plan['phase']) {
                $plan['phase'] = 'rollback_stopping';
                $this->submit($task, $plan, fn (): array => $this->api->changePowerState($connection, $node, $vmId, 'shutdown'));
            }

            return;
        }
        $plan['phase'] = 'rollback_attaching';
        $this->submit($task, $plan, fn (): array => $this->api->attachSystemDisk($connection, $node, $vmId, $plan['device'], $plan['original_volume'], $plan['device_options'], $state['digest']));
    }

    /**
     * @param Reinstallation $plan
     * @return array{digest: string, replacement: string, restored: bool, power: 'running'|'stopped'}
     */
    private function rollbackConfiguration(ProxmoxGuestAssignment $assignment, ProxmoxConnection $connection, string $node, array $plan): array
    {
        $vmId = $assignment->getVmId();
        $config = $this->targetConfiguration($assignment, $node, $connection);
        $disk = self::systemDisk($config, $vmId);
        $replacement = $plan['replacement_volume'] ?? '';
        $restored = $disk['volume'] === $plan['original_volume'];
        $matchesImport = self::matchesImportedDisk($config, $disk, $plan);
        if ('' === $replacement && 'importing' === $plan['phase'] && $matchesImport) {
            $replacement = $disk['volume'];
        }
        $unusedVolume = $restored ? $replacement : $plan['original_volume'];
        if ('' === $replacement || $replacement === $plan['original_volume'] || $disk['device'] !== $plan['device'] || ($disk['options'] !== $plan['device_options'] && !$matchesImport)
            || (!$restored && $disk['volume'] !== $replacement) || ($restored && $disk['size_bytes'] !== $plan['disk_gib'] * self::GIB)
            || self::configurationFingerprint($config, $plan['device']) !== $plan['configuration'] || null === self::unusedDevice($config, $unusedVolume)) {
            throw new \InvalidArgumentException('pve.error.reinstall_rollback');
        }
        $this->assertIdle($connection, $node, $vmId);
        $this->assertDiskPermissions($connection, $vmId, explode(':', $plan['original_volume'], 2)[0], false);
        $power = $this->api->status($connection, $node, $vmId)['status'] ?? null;
        if ('stopped' !== $power && 'running' !== $power) {
            throw new \InvalidArgumentException('pve.error.reinstall_rollback');
        }

        return ['digest' => $config['digest'], 'replacement' => $replacement, 'restored' => $restored, 'power' => $power];
    }

    /** @param Reinstallation $plan */
    private function advanceStep(ProxmoxTask $task, ProxmoxGuestAssignment $assignment, ProxmoxConnection $connection, array $plan): void
    {
        $node = $task->getNode();
        $vmId = $task->getVmId();
        $config = $this->targetConfiguration($assignment, $node, $connection);
        $disk = self::systemDisk($config, $vmId);
        $phase = $plan['phase'];
        if (self::configurationFingerprint($config, $disk['device']) !== $plan['configuration'] || $disk['device'] !== $plan['device']) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        $power = $this->api->status($connection, $node, $vmId)['status'] ?? null;
        $this->assertIdle($connection, $node, $vmId);
        if ('queued' === $phase) {
            $product = 0 === $plan['product_id'] ? null : $this->em->find(ShopProduct::class, $plan['product_id']);
            if ((0 !== $plan['product_id'] && null === $product) || 'stopped' !== $power || $config['digest'] !== $plan['digest'] || $disk['volume'] !== $plan['original_volume']) {
                throw new \InvalidArgumentException('pve.error.reinstall_changed');
            }
            $source = $this->source($assignment, $connection, $node, $product?->getSiteId() ?? 0, $plan['product_id'], $config, $disk);
            if ($source['volume'] !== $plan['source_volume'] || $source['digest'] !== $plan['source_digest']) {
                throw new \InvalidArgumentException('pve.error.reinstall_changed');
            }
            $storage = explode(':', $disk['volume'], 2)[0];
            $this->assertDiskPermissions($connection, $vmId, $storage);
            $plan['phase'] = 'importing';
            // Proxmox writes this serial as part of the imported drive itself.
            $plan['import_serial'] = bin2hex(random_bytes(10));
            $options = self::importOptions($plan['device_options'], $plan['import_serial']);
            $this->submit($task, $plan, fn (): array => $this->api->importSystemDisk($connection, $node, $vmId, $disk['device'], $storage, $source['volume'], $options, $config['digest']));

            return;
        }
        if ('importing' === $phase && isset($plan['import_serial'])) {
            if ('stopped' !== $power || !self::matchesImportedDisk($config, $disk, $plan)) {
                throw new \InvalidArgumentException('pve.error.reinstall_changed');
            }
            $plan['replacement_volume'] = $disk['volume'];
            $plan['phase'] = 'restoring_options';
            $this->submit($task, $plan, fn (): array => $this->api->attachSystemDisk($connection, $node, $vmId, $disk['device'], $disk['volume'], $plan['device_options'], $config['digest']));

            return;
        }
        $oldDevice = self::unusedDevice($config, $plan['original_volume']);
        $targetSize = $plan['disk_gib'] * self::GIB;
        if ($disk['volume'] === $plan['original_volume'] || explode(':', $disk['volume'], 2)[0] !== explode(':', $plan['original_volume'], 2)[0]
            || ($plan['replacement_volume'] ?? $disk['volume']) !== $disk['volume'] || $disk['size_bytes'] > $targetSize || $disk['options'] !== $plan['device_options']) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        if ('removing' === $phase && null === $oldDevice && $targetSize === $disk['size_bytes']) {
            $this->finishReinstallation($task);

            return;
        }
        if (null === $oldDevice || 'removing' === $phase) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        $plan['replacement_volume'] = $disk['volume'];
        if (in_array($phase, ['importing', 'restoring_options', 'resizing'], true)) {
            if ('stopped' !== $power || ('resizing' === $phase && $disk['size_bytes'] !== $targetSize)) {
                throw new \InvalidArgumentException('pve.error.reinstall_changed');
            }
            if ($disk['size_bytes'] < $targetSize) {
                $plan['phase'] = 'resizing';
                $this->submit($task, $plan, fn (): array => $this->api->growDisk($connection, $node, $vmId, 'qemu', $disk['device'], $plan['disk_gib'], $config['digest']));

                return;
            }
            $plan['phase'] = 'starting';
            $plan['started_at'] = time();
            $this->submit($task, $plan, fn (): array => $this->api->changePowerState($connection, $node, $vmId, 'start'));

            return;
        }
        if (in_array($phase, ['starting', 'setting_password'], true) && 'running' === $power && $disk['size_bytes'] === $targetSize
            && $this->bootReady($task, $connection, $config, $plan)) {
            if ('starting' === $phase && isset($plan['install_password_hash'])) {
                $plan['phase'] = 'setting_password';
                $plan['password_requested_at'] = time();
                $this->submitPassword($task, $connection, $plan, $plan['install_password_hash']);

                return;
            }
            $plan['phase'] = 'removing';
            $plan['removal_requested_at'] = time();
            $this->submit($task, $plan, fn (): array => $this->api->removeUnusedDisk($connection, $node, $vmId, $oldDevice, $config['digest']));
        }
    }

    /** @param Reinstallation $plan */
    private function retryPassword(ProxmoxTask $task, #[\SensitiveParameter] array $plan): void
    {
        $now = time();
        if (($plan['password_requested_at'] ?? $task->getCreatedAt()->getTimestamp()) <= $now - self::SUBMISSION_CONFIRMATION_SECONDS) {
            $this->failed($task, 'reinstall_password_unconfirmed');

            return;
        }
        if (($plan['step_submitted_at'] ?? 0) > $now - self::PASSWORD_RETRY_SECONDS) {
            return;
        }
        if (!isset($plan['replacement_volume'], $plan['install_password_hash'])) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        try {
            [$connection, $config, $disk] = $this->observedReplacement($task, $plan, $plan['replacement_volume']);
        } catch (\InvalidArgumentException $exception) {
            if ('pve.error.task_active' === $exception->getMessage()) {
                return;
            }
            throw $exception;
        }
        if ($disk['options'] !== $plan['device_options'] || $disk['size_bytes'] !== $plan['disk_gib'] * self::GIB
            || null === self::unusedDevice($config, $plan['original_volume'])) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        if ('running' === ($this->api->status($connection, $task->getNode(), $task->getVmId())['status'] ?? null)
            && $this->bootReady($task, $connection, $config, $plan)) {
            $this->submitPassword($task, $connection, $plan, $plan['install_password_hash']);
        }
    }

    /** @param Reinstallation $plan */
    private function submitPassword(ProxmoxTask $task, ProxmoxConnection $connection, #[\SensitiveParameter] array $plan, #[\SensitiveParameter] string $hash): void
    {
        if (!preg_match(VmRootPassword::HASH_PATTERN, $hash)) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        try {
            // Resubmission applies the exact hash already assigned to this reinstallation.
            $this->submit($task, $plan, fn (): array => $this->api->setRootPasswordHash($connection, $task->getNode(), $task->getVmId(), $hash));
        } catch (ProxmoxApiException $exception) {
            $status = $exception->statusCode;
            if ($status >= 400 && $status < 500 && !in_array($status, [408, 429], true)) {
                $this->failed($task, in_array($status, [401, 403], true) ? 'permission_denied' : 'reinstall_password_rejected');
            }
        }
    }

    /**
     * @param array<string, string> $config
     * @param Reinstallation $plan
     */
    private function bootReady(ProxmoxTask $task, ProxmoxConnection $connection, array $config, array $plan): bool
    {
        $node = $task->getNode();
        $vmId = $task->getVmId();

        return isset($plan['started_at']) && $this->api->cloudInitBootFinished($connection, $node, $vmId, $plan['started_at'])
            && $this->networkReady($connection, $node, $vmId, $config);
    }

    /** @param Reinstallation $plan */
    private function reconcileSubmission(ProxmoxTask $task, array $plan): void
    {
        $removing = 'removing' === $plan['phase'];
        try {
            if ($removing && $this->isRemovalConfirmed($task, $plan)) {
                $this->finishReinstallation($task);

                return;
            }
            if (!$removing && $this->isStepConfirmed($task, $plan)) {
                // Persist the observation before the next poll can dispatch a different step.
                $task->reinstallationStepDispatched(null);
                $this->em->flush();

                return;
            }
        } catch (\InvalidArgumentException $exception) {
            if ('pve.error.task_active' !== $exception->getMessage()) {
                throw $exception;
            }
        } catch (ProxmoxApiException $exception) {
            if (in_array($exception->statusCode, [401, 403], true)) {
                throw $exception;
            }
        }
        $requestedAt = ($removing ? ($plan['removal_requested_at'] ?? null) : ($plan['step_submitted_at'] ?? null)) ?? $task->getCreatedAt()->getTimestamp();
        if ($requestedAt <= time() - self::SUBMISSION_CONFIRMATION_SECONDS) {
            $this->failed($task, $removing ? 'reinstall_removal_unconfirmed' : 'reinstall_step_unconfirmed');
        }
    }

    /** @param Reinstallation $plan */
    private function isStepConfirmed(ProxmoxTask $task, array $plan): bool
    {
        if (!isset($plan['replacement_volume'])) {
            return false;
        }
        [$connection, $config, $disk] = $this->observedReplacement($task, $plan, $plan['replacement_volume']);
        if (null === self::unusedDevice($config, $plan['original_volume'])) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        $phase = $plan['phase'];
        if ($disk['options'] !== $plan['device_options']) {
            if ('restoring_options' === $phase && self::matchesImportedDisk($config, $disk, $plan)) {
                return false;
            }
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }

        $power = $this->api->status($connection, $task->getNode(), $task->getVmId())['status'] ?? null;
        $fullSize = $disk['size_bytes'] === $plan['disk_gib'] * self::GIB;
        if ('starting' === $phase) {
            if (!$fullSize || !isset($plan['started_at'])) {
                throw new \InvalidArgumentException('pve.error.reinstall_changed');
            }

            return 'running' === $power;
        }
        if ('stopped' !== $power) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }

        return 'restoring_options' === $phase || $fullSize;
    }

    /** @param Reinstallation $plan */
    private function isRemovalConfirmed(ProxmoxTask $task, array $plan): bool
    {
        if (!isset($plan['replacement_volume'], $plan['started_at'])) {
            return false;
        }
        [$connection, $config, $disk] = $this->observedReplacement($task, $plan, $plan['replacement_volume']);
        if ($disk['options'] !== $plan['device_options'] || $disk['size_bytes'] !== $plan['disk_gib'] * self::GIB) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        if (null !== self::unusedDevice($config, $plan['original_volume'])) {
            return false;
        }

        // Require the replacement to be visible on the same storage. A filtered or empty list is not proof of deletion.
        $storage = explode(':', $plan['original_volume'], 2)[0];
        $volumes = array_column($this->api->storageContent($connection, $task->getNode(), $storage, 'images', $task->getVmId()), 'volid');

        return in_array($plan['replacement_volume'], $volumes, true) && !in_array($plan['original_volume'], $volumes, true);
    }

    /**
     * @param Reinstallation $plan
     * @return array{ProxmoxConnection, array<string, string>, array{device: string, size_bytes: int, volume: string, options: string}}
     */
    private function observedReplacement(ProxmoxTask $task, array $plan, string $replacement): array
    {
        $assignment = $this->checkedAssignment($task, $plan);
        $connection = $task->getCluster()->connection($this->crypto);
        $node = $task->getNode();
        $vmId = $task->getVmId();
        $this->assertIdle($connection, $node, $vmId);
        $config = $this->targetConfiguration($assignment, $node, $connection);
        $disk = self::systemDisk($config, $vmId);
        if ($disk['device'] !== $plan['device'] || $disk['volume'] !== $replacement || $replacement === $plan['original_volume']
            || explode(':', $replacement, 2)[0] !== explode(':', $plan['original_volume'], 2)[0]
            || $disk['size_bytes'] > $plan['disk_gib'] * self::GIB || self::configurationFingerprint($config, $plan['device']) !== $plan['configuration']) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }

        return [$connection, $config, $disk];
    }

    private function finishReinstallation(ProxmoxTask $task, ?User $actor = null, ?string $note = null): void
    {
        $task->finish(true, 'OK');
        $this->audit->log($actor, 'proxmox.reinstallation_ready', [
            'cluster_id' => $task->getCluster()->getId(), 'vm_id' => $task->getVmId(), 'task_id' => $task->getId(),
        ] + (null === $note ? [] : ['operator_note' => $note]));
        $this->em->flush();
    }

    /**
     * @param Reinstallation $plan
     * @param \Closure(): array<array-key, mixed> $write
     */
    private function submit(ProxmoxTask $task, array $plan, \Closure $write): void
    {
        $plan['step_submitted_at'] = time();
        $task->beginReinstallationStep($plan);
        $this->em->flush();
        $task->reinstallationStepDispatched(JsonMap::strings($write())['task'] ?? null);
        $this->em->flush();
    }

    private function failed(ProxmoxTask $task, string $reason): void
    {
        if ('queued' === $task->getState()) {
            $task->finish(false, 'permission_denied' === $reason ? $reason : 'preflight_failed');
        } else {
            $task->uncertain($reason);
        }
        $this->em->flush();
    }

    /** @return array<string, string>&array{digest: string} */
    private function targetConfiguration(ProxmoxGuestAssignment $assignment, string $node, ProxmoxConnection $connection): array
    {
        if ('qemu' !== $assignment->getKind()) {
            throw new \InvalidArgumentException('pve.error.reinstall_unavailable');
        }
        $vmId = $assignment->getVmId();
        $guest = array_find($this->api->clusterResources($connection, 'vm'), static fn (array $row): bool => (int) ($row['vmid'] ?? 0) === $vmId);
        if (($guest['type'] ?? null) !== 'qemu' || ($guest['node'] ?? null) !== $node || !empty($guest['template'])
            || !in_array($assignment->getIdentityTag(), explode(';', (string) ($guest['tags'] ?? '')), true)) {
            throw new \InvalidArgumentException('pve.error.identity');
        }
        $config = $this->configuration($connection, $node, $vmId);
        if (!in_array($assignment->getIdentityTag(), explode(';', $config['tags'] ?? ''), true)) {
            throw new \InvalidArgumentException('pve.error.identity');
        }
        if (!isset($config['digest']) || '' === $config['digest'] || '' !== ($config['lock'] ?? '') || '1' === ($config['protection'] ?? '') || '1' === ($config['template'] ?? '')) {
            throw new \InvalidArgumentException('pve.error.reinstall_unavailable');
        }

        return $config;
    }

    /** @return array<string, string> */
    private function configuration(ProxmoxConnection $connection, string $node, int $vmId): array
    {
        $config = [];
        $upstream = $this->api->guestConfig($connection, $node, $vmId, current: true);
        foreach (array_keys($upstream) as $key) {
            if (is_string($key) && is_scalar($upstream[$key])) {
                $config[$key] = (string) $upstream[$key];
            }
        }

        return $config;
    }

    private function assertIdle(ProxmoxConnection $connection, string $node, int $vmId): void
    {
        if (!$this->api->hasPrivilege($connection, '/nodes/'.$node, 'Sys.Audit')) {
            throw new \InvalidArgumentException('pve.error.permissions');
        }
        if ([] !== $this->api->activeTasks($connection, $node, $vmId) || $this->api->hasPendingGuestConfiguration($connection, $node, $vmId)) {
            throw new \InvalidArgumentException('pve.error.task_active');
        }
        if (array_any($this->api->snapshots($connection, $node, $vmId), static fn (array $row): bool => ($row['name'] ?? null) !== 'current')) {
            throw new \InvalidArgumentException('pve.error.disk_snapshots');
        }
    }

    private function assertDiskPermissions(ProxmoxConnection $connection, int $vmId, string $storage, bool $guestAgentRequired = true): void
    {
        $permissions = $this->api->privilegesAt($connection, '/vms/'.$vmId);
        if (!isset($permissions['VM.Config.Disk'], $permissions['VM.PowerMgmt'])) {
            throw new \InvalidArgumentException('pve.error.permissions');
        }
        if ($guestAgentRequired && !isset($permissions['VM.GuestAgent.Unrestricted']) && !isset($permissions['VM.GuestAgent.Audit'], $permissions['VM.GuestAgent.FileRead'])) {
            throw new \InvalidArgumentException('pve.error.permissions');
        }
        if (!$this->api->hasPrivilege($connection, '/storage/'.$storage, 'Datastore.AllocateSpace')) {
            throw new \InvalidArgumentException('pve.error.permissions');
        }
    }

    /**
     * @param array<string, string> $target
     * @param array{device: string, size_bytes: int, volume: string, options: string} $disk
     * @return array{volume: string, digest: string}
     */
    private function source(ProxmoxGuestAssignment $assignment, ProxmoxConnection $connection, string $node, int $siteId, int $productId, array $target, array $disk): array
    {
        if (0 === $productId) {
            $templateId = $this->originalTemplate($assignment);
        } else {
            $product = $this->em->find(ShopProduct::class, $productId);
            $templateId = null !== $product && $product->getSiteId() === $siteId ? self::templateId($product, $assignment->getCluster(), $node) : 0;
        }
        $source = $templateId > 0 ? array_find($this->api->clusterResources($connection, 'vm'), static fn (array $row): bool => (int) ($row['vmid'] ?? 0) === $templateId) : null;
        if (($source['type'] ?? null) !== 'qemu' || ($source['node'] ?? null) !== $node || empty($source['template'])) {
            throw new \InvalidArgumentException('pve.error.install_template');
        }
        if (!$this->api->hasPrivilege($connection, '/vms/'.$templateId, 'VM.Clone')) {
            throw new \InvalidArgumentException('pve.error.permissions');
        }
        $config = $this->configuration($connection, $node, $templateId);
        $sourceDisk = self::systemDisk($config, $templateId, true);
        if ('' === ($config['digest'] ?? '') || '1' !== ($config['template'] ?? '') || '' !== ($config['lock'] ?? '') || '' !== ($config['cipassword'] ?? '')
            || $sourceDisk['size_bytes'] > $disk['size_bytes'] || ($config['bios'] ?? 'seabios') !== ($target['bios'] ?? 'seabios')
            || preg_replace('/\d+$/D', '', $sourceDisk['device']) !== preg_replace('/\d+$/D', '', $disk['device'])) {
            throw new \InvalidArgumentException('pve.error.install_template');
        }

        return ['volume' => $sourceDisk['volume'], 'digest' => $config['digest']];
    }

    private function originalTemplate(ProxmoxGuestAssignment $assignment): int
    {
        $task = $this->em->getRepository(ProxmoxTask::class)->findOneBy([
            'cluster' => $assignment->getCluster(), 'kind' => 'qemu', 'action' => 'install-template',
            'targetVmId' => $assignment->getVmId(), 'state' => 'succeeded',
        ], ['id' => 'DESC']);
        if (null === $task || $assignment->getIdentityTag() !== 'easywi-owned-'.substr($task->getRequestKey(), 0, 16)
            || ($task->getInstallation()['customer_id'] ?? null) !== $assignment->getCustomer()->getId()) {
            return 0;
        }

        return $task->getVmId();
    }

    private static function templateId(ShopProduct $product, ProxmoxCluster $cluster, string $node): int
    {
        $options = $product->getProvisioningOptions();
        if (ShopProductKind::Proxmox !== $product->getKind() || !$product->isActive() || (!$product->isPublicActive() && !$product->isCustomerActive())
            || !$product->getCategory()->isActive() || (int) ($options['proxmox_cluster_id'] ?? 0) !== $cluster->getId() || ($options['proxmox_node'] ?? null) !== $node) {
            return 0;
        }

        return (int) ($options['proxmox_template_id'] ?? 0);
    }

    /**
     * @param array<string, string> $config
     * @return array{device: string, size_bytes: int, volume: string, options: string}
     */
    private static function eligibleTargetDisk(array $config, int $vmId): array
    {
        $disk = self::systemDisk($config, $vmId);
        $agent = explode(',', $config['agent'] ?? '');
        if ('' === ($config['sshkeys'] ?? '') || '' === ($config['ciuser'] ?? '') || (!in_array('1', $agent, true) && !in_array('enabled=1', $agent, true))
            || 0 !== $disk['size_bytes'] % self::GIB || !ProxmoxDisks::validOptions($disk['options']) || null !== self::unusedDevice($config)) {
            throw new \InvalidArgumentException('pve.error.reinstall_unavailable');
        }

        return $disk;
    }

    /**
     * @param array<string, string> $config
     * @return array{device: string, size_bytes: int, volume: string, options: string}
     */
    private static function systemDisk(array $config, int $vmId, bool $template = false): array
    {
        $error = $template ? 'pve.error.install_template' : 'pve.error.reinstall_unavailable';
        $disks = ProxmoxDisks::attached($config, 'qemu');
        if (1 !== count($disks) || !VmProvisioningSettings::hasCloudInit($config) || '' === ($config['net0'] ?? '') || '' !== ($config['cicustom'] ?? '')
            || 'nocloud' !== ($config['citype'] ?? 'nocloud') || !in_array($config['ostype'] ?? 'l26', ['l26', 'l24', 'other'], true)) {
            throw new \InvalidArgumentException($error);
        }
        $disk = $disks[0];
        foreach ($config as $key => $value) {
            if (preg_match('/^(?:net[1-9]\d*|args|tpmstate\d+|hostpci\d+)$/D', $key)
                || (ProxmoxDisks::validDevice('qemu', $key) && $key !== $disk['device'] && !VmProvisioningSettings::hasCloudInit([$key => $value]))) {
                throw new \InvalidArgumentException($error);
            }
        }
        $raw = explode(',', $config[$disk['device']] ?? '');
        $volume = array_shift($raw);
        if (!preg_match('~^[A-Za-z][A-Za-z0-9_.-]*:(?:'.$vmId.'/)?(?:vm|base)-'.$vmId.'-[A-Za-z0-9_.-]+$~D', $volume)) {
            throw new \InvalidArgumentException($error);
        }
        $options = array_values(array_filter($raw, static fn (string $part): bool => !str_starts_with($part, 'size=')));
        sort($options, SORT_STRING);

        return ['device' => $disk['device'], 'size_bytes' => $disk['size_bytes'], 'volume' => $volume, 'options' => [] === $options ? '' : ','.implode(',', $options)];
    }

    /** @param array<string, string> $config */
    private static function configurationFingerprint(array $config, string $device): string
    {
        unset($config[$device], $config['digest'], $config['lock']);
        $config = array_filter($config, static fn (string $key): bool => !preg_match('/^unused\d+$/D', $key), ARRAY_FILTER_USE_KEY);
        ksort($config, SORT_STRING);

        return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $config
     * @param array{device: string, size_bytes: int, volume: string, options: string} $disk
     * @param Reinstallation $plan
     */
    private static function matchesImportedDisk(array $config, array $disk, array $plan): bool
    {
        // Preflight required an empty list of unused disks. A second new entry
        // can indicate an unrelated change or a partial import.
        $unused = array_filter($config, static fn (string $device): bool => 1 === preg_match('/^unused\d+$/D', $device), ARRAY_FILTER_USE_KEY);

        return isset($plan['import_serial']) && $disk['device'] === $plan['device']
            && $disk['volume'] !== $plan['original_volume'] && explode(':', $disk['volume'], 2)[0] === explode(':', $plan['original_volume'], 2)[0]
            && $disk['size_bytes'] <= $plan['disk_gib'] * self::GIB && $disk['options'] === self::importOptions($plan['device_options'], $plan['import_serial'])
            && 1 === count($unused) && null !== self::unusedDevice($unused, $plan['original_volume']);
    }

    private static function importOptions(string $options, string $serial): string
    {
        $parts = array_filter(explode(',', ltrim($options, ',')), static fn (string $part): bool => '' !== $part && !str_starts_with($part, 'serial='));
        $parts[] = 'serial='.$serial;
        sort($parts, SORT_STRING);

        return ','.implode(',', $parts);
    }

    /** @param array<string, string> $config */
    private static function unusedDevice(array $config, ?string $volume = null): ?string
    {
        foreach ($config as $device => $value) {
            if (preg_match('/^unused\d+$/D', $device) && (null === $volume || explode(',', $value, 2)[0] === $volume)) {
                return $device;
            }
        }

        return null;
    }

    /** @param array<string, string> $config */
    private function networkReady(ProxmoxConnection $connection, string $node, int $vmId, array $config): bool
    {
        if (!preg_match('/(?:^|,)[a-z0-9_]+=([0-9a-f]{2}(?::[0-9a-f]{2}){5})(?:,|$)/iD', $config['net0'] ?? '', $mac)) {
            return false;
        }
        $expected = [];
        foreach (explode(',', $config['ipconfig0'] ?? '') as $entry) {
            [$key, $value] = array_pad(explode('=', $entry, 2), 2, '');
            if (!in_array($key, ['ip', 'ip6'], true) || !str_contains($value, '/')) {
                continue;
            }
            [$address, $prefix] = array_pad(explode('/', $value, 2), 2, '');
            $packed = inet_pton($address);
            $normalized = false === $packed ? false : inet_ntop($packed);
            if (false === $normalized || !ctype_digit($prefix)) {
                return false;
            }
            $expected[] = $normalized.'/'.(int) $prefix;
        }
        $network = VmGuestNetworkReport::fromQga($this->api->guestAgentRead($connection, $node, $vmId, 'network-get-interfaces'));
        foreach ($network['interfaces'] as $interface) {
            if (strtolower($mac[1]) !== $interface['mac']) {
                continue;
            }
            $observed = array_map(static fn (array $address): string => $address['address'].'/'.$address['prefix'], $interface['addresses']);
            if ([] === $expected) {
                return array_any($interface['addresses'], static fn (array $address): bool => false !== filter_var($address['address'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE));
            }

            return [] === array_diff($expected, $observed);
        }

        return false;
    }
}
