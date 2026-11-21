<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Doctrine\EntityIdentifier;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Nodes\Application\VmGuestNetworkReport;
use App\Module\Nodes\Application\VmProvisioningSettings;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/** @psalm-import-type Reinstallation from ProxmoxTask */
final readonly class ProxmoxReinstallation
{
    private const GIB = 1073741824;

    public function __construct(private ProxmoxApiClient $api, private SecretsCryptoInterface $crypto, private EntityManagerInterface $em, private LockFactory $locks, private AuditLoggerInterface $audit)
    {
    }

    /** @return array{digest: string, images: array<int, string>}|null */
    public function choices(ProxmoxGuestAssignment $assignment, string $node, int $siteId): ?array
    {
        $cluster = $assignment->getCluster();
        $images = [];
        foreach ($this->em->getRepository(ShopProduct::class)->findBy(['siteId' => $siteId, 'kind' => ShopProductKind::Proxmox, 'isActive' => true], ['name' => 'ASC']) as $product) {
            if (0 < self::templateId($product, $cluster, $node)) {
                $images[EntityIdentifier::require($product->getId())] = $product->getName();
            }
        }
        if ([] === $images) {
            return null;
        }
        try {
            $config = $this->targetConfiguration($assignment, $node, $cluster->connection($this->crypto));
            self::eligibleTargetDisk($config, $assignment->getVmId());
        } catch (\InvalidArgumentException) {
            return null;
        }

        return ['digest' => $config['digest'], 'images' => $images];
    }

    /** @return Reinstallation */
    public function plan(ProxmoxGuestAssignment $assignment, string $node, int $siteId, int $productId, string $expectedDigest): array
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
        $source = $this->source($cluster, $connection, $node, $siteId, $productId, $config, $disk);

        return [
            'phase' => 'queued', 'assignment_id' => EntityIdentifier::require($assignment->getId()),
            'tag' => $assignment->getIdentityTag(), 'product_id' => $productId, 'digest' => $config['digest'],
            'configuration' => self::configurationFingerprint($config, $disk['device']),
            'original_volume' => $disk['volume'], 'device' => $disk['device'], 'device_options' => $disk['options'], 'disk_gib' => intdiv($disk['size_bytes'], self::GIB),
            'source_volume' => $source['volume'], 'source_digest' => $source['digest'],
        ];
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
                $task->uncertain();
                $this->em->flush();

                return;
            }
            if ($task->getCreatedAt() < new \DateTimeImmutable('-60 minutes')) {
                $this->failed($task, 'reinstall_timeout');

                return;
            }
            $assignment = $this->em->find(ProxmoxGuestAssignment::class, $plan['assignment_id']);
            if (null === $assignment || $assignment->getCustomer()->getId() !== $task->getActorId() || $assignment->getIdentityTag() !== $plan['tag']
                || $assignment->getCluster()->getId() !== $task->getCluster()->getId() || 'qemu' !== $assignment->getKind() || $assignment->getVmId() !== $task->getVmId()) {
                $this->failed($task, 'identity_mismatch');

                return;
            }
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
            $this->advanceStep($task, $assignment, $connection, $plan);
        } catch (\InvalidArgumentException $exception) {
            $this->failed($task, match ($exception->getMessage()) {
                'pve.error.identity' => 'identity_mismatch',
                'pve.error.permissions' => 'permission_denied',
                default => 'reinstall_configuration_changed',
            });
        } catch (ProxmoxApiException $exception) {
            if ('submitting' === $task->getState()) {
                $this->failed($task, 'dispatch_unknown');
            } elseif (in_array($exception->statusCode, [401, 403], true)) {
                $this->failed($task, 'permission_denied');
            }
        } finally {
            $lock->release();
        }
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
            $product = $this->em->find(ShopProduct::class, $plan['product_id']);
            if (null === $product || 'stopped' !== $power || $config['digest'] !== $plan['digest'] || $disk['volume'] !== $plan['original_volume']) {
                throw new \InvalidArgumentException('pve.error.reinstall_changed');
            }
            $source = $this->source($task->getCluster(), $connection, $node, $product->getSiteId(), $plan['product_id'], $config, $disk);
            if ($source['volume'] !== $plan['source_volume'] || $source['digest'] !== $plan['source_digest']) {
                throw new \InvalidArgumentException('pve.error.reinstall_changed');
            }
            $storage = explode(':', $disk['volume'], 2)[0];
            $this->assertDiskPermissions($connection, $vmId, $storage);
            $plan['phase'] = 'importing';
            $this->submit($task, $plan, fn (): array => $this->api->importSystemDisk($connection, $node, $vmId, $disk['device'], $storage, $source['volume'], $disk['options'], $config['digest']));

            return;
        }
        $oldDevice = self::unusedDevice($config, $plan['original_volume']);
        $targetSize = $plan['disk_gib'] * self::GIB;
        if ($disk['volume'] === $plan['original_volume'] || explode(':', $disk['volume'], 2)[0] !== explode(':', $plan['original_volume'], 2)[0]
            || ($plan['replacement_volume'] ?? $disk['volume']) !== $disk['volume'] || $disk['size_bytes'] > $targetSize || $disk['options'] !== $plan['device_options']) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        if ('removing' === $phase && null === $oldDevice && $targetSize === $disk['size_bytes']) {
            $task->finish(true, 'OK');
            $this->audit->log(null, 'proxmox.reinstallation_ready', ['cluster_id' => $task->getCluster()->getId(), 'vm_id' => $vmId, 'task_id' => $task->getId()]);
            $this->em->flush();

            return;
        }
        if (null === $oldDevice || 'removing' === $phase) {
            throw new \InvalidArgumentException('pve.error.reinstall_changed');
        }
        $plan['replacement_volume'] = $disk['volume'];
        if (in_array($phase, ['importing', 'resizing'], true)) {
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
        if ('starting' === $phase && 'running' === $power && isset($plan['started_at']) && $disk['size_bytes'] === $targetSize
            && $this->api->cloudInitBootFinished($connection, $node, $vmId, $plan['started_at']) && $this->networkReady($connection, $node, $vmId, $config)) {
            $plan['phase'] = 'removing';
            $this->submit($task, $plan, fn (): array => $this->api->removeUnusedDisk($connection, $node, $vmId, $oldDevice, $config['digest']));
        }
    }

    /**
     * @param Reinstallation $plan
     * @param \Closure(): array<array-key, mixed> $write
     */
    private function submit(ProxmoxTask $task, array $plan, \Closure $write): void
    {
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
        $path = '/nodes/'.$node;
        if (empty($this->api->permissions($connection, $path)[$path]['Sys.Audit'])) {
            throw new \InvalidArgumentException('pve.error.permissions');
        }
        if ([] !== $this->api->activeTasks($connection, $node, $vmId) || $this->api->hasPendingGuestConfiguration($connection, $node, $vmId)) {
            throw new \InvalidArgumentException('pve.error.task_active');
        }
        if (array_any($this->api->snapshots($connection, $node, $vmId), static fn (array $row): bool => ($row['name'] ?? null) !== 'current')) {
            throw new \InvalidArgumentException('pve.error.disk_snapshots');
        }
    }

    private function assertDiskPermissions(ProxmoxConnection $connection, int $vmId, string $storage): void
    {
        $path = '/vms/'.$vmId;
        $permissions = JsonMap::fromValue($this->api->permissions($connection, $path)[$path] ?? null) ?? [];
        foreach (['VM.Config.Disk', 'VM.PowerMgmt'] as $permission) {
            if (empty($permissions[$permission])) {
                throw new \InvalidArgumentException('pve.error.permissions');
            }
        }
        if (empty($permissions['VM.GuestAgent.Unrestricted']) && (empty($permissions['VM.GuestAgent.Audit']) || empty($permissions['VM.GuestAgent.FileRead']))) {
            throw new \InvalidArgumentException('pve.error.permissions');
        }
        $path = '/storage/'.$storage;
        if (empty($this->api->permissions($connection, $path)[$path]['Datastore.AllocateSpace'])) {
            throw new \InvalidArgumentException('pve.error.permissions');
        }
    }

    /**
     * @param array<string, string> $target
     * @param array{device: string, size_bytes: int, volume: string, options: string} $disk
     * @return array{volume: string, digest: string}
     */
    private function source(ProxmoxCluster $cluster, ProxmoxConnection $connection, string $node, int $siteId, int $productId, array $target, array $disk): array
    {
        $product = $this->em->find(ShopProduct::class, $productId);
        $templateId = null !== $product && $product->getSiteId() === $siteId ? self::templateId($product, $cluster, $node) : 0;
        $source = $templateId > 0 ? array_find($this->api->clusterResources($connection, 'vm'), static fn (array $row): bool => (int) ($row['vmid'] ?? 0) === $templateId) : null;
        if (($source['type'] ?? null) !== 'qemu' || ($source['node'] ?? null) !== $node || empty($source['template'])) {
            throw new \InvalidArgumentException('pve.error.install_template');
        }
        $path = '/vms/'.$templateId;
        if (empty($this->api->permissions($connection, $path)[$path]['VM.Clone'])) {
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
            || 0 !== $disk['size_bytes'] % self::GIB || null !== self::unusedDevice($config)) {
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
