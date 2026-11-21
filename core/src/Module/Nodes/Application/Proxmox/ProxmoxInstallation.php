<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Infrastructure\Config\JsonMap;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\User;
use App\Module\Nodes\Application\VmGuestNetworkReport;
use App\Module\Nodes\Application\VmProvisioningSettings;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * @psalm-import-type Installation from ProxmoxTask
 * @psalm-import-type RentalResources from ProxmoxTask
 */
final readonly class ProxmoxInstallation
{
    public function __construct(private ProxmoxApiClient $api, private SecretsCryptoInterface $crypto, private EntityManagerInterface $em, private LockFactory $locks, private AuditLoggerInterface $audit, private ProxmoxIpPools $ipPools)
    {
    }

    /** @return Installation */
    public static function settings(array $input): array
    {
        $username = $input['username'] ?? '';
        if (!is_string($username) || !preg_match('/^(?!root$)[a-z][a-z0-9_-]{0,30}$/D', $username)) {
            throw new \InvalidArgumentException('vm_config.invalid_username');
        }
        $customerId = '' === ($input['customer_id'] ?? '') ? null : filter_var($input['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $customerId) {
            throw new \InvalidArgumentException('pve.error.customer');
        }

        return ['hostname' => VmProvisioningSettings::hostname($input['name'] ?? null), 'username' => $username, 'keys' => VmProvisioningSettings::publicKeys($input['ssh_keys'] ?? null), 'phase' => 'cloning', 'customer_id' => $customerId];
    }

    public static function assertTemplate(array $config): void
    {
        if (!VmProvisioningSettings::hasCloudInit($config) || empty($config['net0']) || !empty($config['cipassword']) || !empty($config['cicustom'])) {
            throw new \InvalidArgumentException('pve.error.install_template');
        }
        foreach (array_keys($config) as $key) {
            if (is_string($key) && preg_match('/^(?:net|ipconfig)[1-9][0-9]*$/D', $key)) {
                throw new \InvalidArgumentException('pve.error.install_template');
            }
        }
    }

    public static function marker(string $requestKey): string
    {
        return 'Easy-WI installation '.$requestKey;
    }

    /**
     * @param RentalResources $resources
     * @return array{device:string, size_bytes:int, minimum_gib:int}
     */
    public static function rentalSystemDisk(array $config, array $resources): array
    {
        $disks = ProxmoxDisks::attached($config, 'qemu');
        if (1 !== count($disks) || $disks[0]['size_bytes'] > $resources['disk_gib'] * 1073741824 || filter_var($config['onboot'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new \InvalidArgumentException('pve.error.rental_template_resources');
        }
        $disk = $disks[0];
        foreach (array_keys($config) as $device) {
            if (!is_string($device)) {
                continue;
            }
            if (preg_match('/^numa\d+$/D', $device) || (ProxmoxDisks::validDevice('qemu', $device) && $disk['device'] !== $device
                && !VmProvisioningSettings::hasCloudInit([$device => $config[$device]]))) {
                throw new \InvalidArgumentException('pve.error.rental_template_resources');
            }
        }

        return $disk;
    }

    /** @param RentalResources $resources */
    private static function assertRentalResources(array $config, array $resources): void
    {
        $disk = self::rentalSystemDisk($config, $resources);
        $cores = filter_var($config['cores'] ?? 1, FILTER_VALIDATE_INT);
        $sockets = filter_var($config['sockets'] ?? 1, FILTER_VALIDATE_INT);
        $memory = filter_var($config['memory'] ?? null, FILTER_VALIDATE_INT);
        $balloon = filter_var($config['balloon'] ?? 0, FILTER_VALIDATE_INT);
        $vcpus = filter_var($config['vcpus'] ?? 0, FILTER_VALIDATE_INT);
        $cpuLimit = filter_var($config['cpulimit'] ?? 0, FILTER_VALIDATE_FLOAT);
        if (false === $cores || false === $sockets || $cores < 1 || $cores > 256 || $sockets < 1 || $sockets > 256
            || $cores * $sockets !== $resources['cores'] || !in_array($vcpus, [0, $resources['cores']], true)
            || $memory !== $resources['memory_mib'] || !in_array($balloon, [0, $memory], true) || (0.0 !== $cpuLimit && (false === $cpuLimit || $cpuLimit < $resources['cores']))
            || $disk['size_bytes'] !== $resources['disk_gib'] * 1073741824) {
            throw new \InvalidArgumentException('pve.error.rental_resources_unconfirmed');
        }
    }

    /** @param Installation $settings */
    private static function assertRentalCloudInit(array $config, array $settings, string $ipConfig): void
    {
        self::assertTemplate($config);
        $values = JsonMap::strings($config);
        $expectedNetwork = explode(',', $ipConfig);
        $appliedNetwork = explode(',', $values['ipconfig0'] ?? '');
        sort($expectedNetwork, SORT_STRING);
        sort($appliedNetwork, SORT_STRING);
        if (($values['name'] ?? null) !== $settings['hostname'] || ($values['ciuser'] ?? null) !== $settings['username']
            || trim(rawurldecode($values['sshkeys'] ?? '')) !== implode("\n", $settings['keys']) || $appliedNetwork !== $expectedNetwork) {
            throw new \InvalidArgumentException('pve.error.rental_cloud_init_unconfirmed');
        }
    }

    public function advance(ProxmoxTask $task): void
    {
        $lock = $this->locks->createLock('proxmox.installation.'.\App\Infrastructure\Doctrine\EntityIdentifier::require($task->getId()), 90);
        if (!$lock->acquire()) {
            return;
        }
        try {
            $this->em->refresh($task);
            $settings = $task->getInstallation();
            $id = $task->getTargetVmId();
            if (null === $settings || null === $id || !$task->isActive() || 'uncertain' === $task->getState()) {
                return;
            }
            if ($task->getCreatedAt() < new \DateTimeImmutable('-30 minutes')) {
                if ('queued' === $task->getState()) {
                    $task->finish(false, 'preflight_failed');
                } else {
                    $task->uncertain('installation_timeout');
                }
                $this->em->flush();

                return;
            }
            $connection = $task->getCluster()->connection($this->crypto);
            $node = $task->getNode();
            if ('queued' === $task->getState()) {
                $this->dispatchQueued($task, $connection, $id, $settings);

                return;
            }
            $phase = $settings['phase'];
            $upid = $task->getUpid();
            $recoveringClone = 'cloning' === $phase && null === $upid;
            if ($recoveringClone && !isset($settings['clone_submitted'])) {
                // Older synchronous callers do not hold the scheduler's installation lock during submission.
                return;
            }
            if (in_array($phase, ['cloning', 'resizing', 'starting'], true) && null !== $upid) {
                $result = $this->api->taskStatus($connection, $node, $upid);
                if ('stopped' !== ($result['status'] ?? '')) {
                    return;
                }
                if ('OK' !== ($result['exitstatus'] ?? '')) {
                    $task->finish(false, 'upstream_failed');
                    $this->em->flush();

                    return;
                }
            }
            if ((in_array($phase, ['configuring', 'booting'], true) || null !== $upid) && !$this->rentalCanProceed($task)) {
                $task->finish(false, 'cancelled');
                $this->em->flush();

                return;
            }

            $resources = $settings['resources'] ?? null;
            if ($recoveringClone) {
                $guest = array_find($this->api->clusterResources($connection, 'vm'), static fn (array $row): bool => (int) ($row['vmid'] ?? 0) === $id);
                if (null === $guest) {
                    return;
                }
                if ('qemu' !== ($guest['type'] ?? null) || $node !== ($guest['node'] ?? null) || !empty($guest['template'])) {
                    $task->uncertain('identity_mismatch');
                    $this->em->flush();

                    return;
                }
            }
            $config = $this->api->guestConfig($connection, $node, $id, current: null !== $resources);
            if ($recoveringClone && !empty($config['lock'])) {
                return;
            }
            $digest = JsonMap::strings($config)['digest'] ?? '';
            if (($config['description'] ?? null) !== self::marker($task->getRequestKey()) || '' === $digest || !empty($config['template'])) {
                $task->uncertain('identity_mismatch');
                $this->em->flush();

                return;
            }
            if ($recoveringClone) {
                // Proxmox registers qmclone against the source ID and may unlock the clone before the worker exits.
                $path = '/nodes/'.$node;
                if (empty($this->api->permissions($connection, $path)[$path]['Sys.Audit'])
                    || [] !== $this->api->activeTasks($connection, $node, $task->getVmId())
                    || [] !== $this->api->activeTasks($connection, $node, $id)) {
                    return;
                }
                if (!$this->rentalCanProceed($task)) {
                    $task->finish(false, 'cancelled');
                    $this->em->flush();

                    return;
                }
            }
            $leases = $this->ipPools->leases($task);
            $net0 = JsonMap::strings($config)['net0'] ?? '';
            foreach ($leases as $lease) {
                ProxmoxIpPools::assertPort($net0, $lease->getBridge(), $lease->getVlan());
            }
            $ipConfig = ProxmoxIpPools::cloudInit($leases);
            $status = $this->api->status($connection, $node, $id);
            if (in_array($phase, ['cloning', 'resizing', 'configuring'], true)) {
                if ('stopped' !== ($status['status'] ?? null) || !empty($config['lock'])) {
                    return;
                }
                self::assertTemplate($config);
                if (null !== $resources && !$this->prepareRentalDisk($task, $connection, $id, $config, $digest, $resources)) {
                    return;
                }
                if (!$this->rentalCanProceed($task)) {
                    $task->finish(false, 'cancelled');
                    $this->em->flush();

                    return;
                }
                $this->api->updateProvisioning($connection, $node, $id, $settings['hostname'], $settings['username'], $settings['keys'], $digest, true, $ipConfig, $resources);
                if (null !== $resources) {
                    $applied = $this->api->guestConfig($connection, $node, $id, current: true);
                    if (($applied['description'] ?? null) !== self::marker($task->getRequestKey()) || !empty($applied['lock'])) {
                        throw new \InvalidArgumentException('pve.error.identity');
                    }
                    self::assertRentalResources($applied, $resources);
                    self::assertRentalCloudInit($applied, $settings, $ipConfig);
                }
                // Persist this before the start call. A lost response may still have started the guest.
                $task->beginInstallationStart();
                $this->em->flush();
                $start = JsonMap::strings($this->api->changePowerState($connection, $node, $id, 'start'))['task'] ?? '';
                if ('' !== $start) {
                    $task->dispatched($start);
                    $this->em->flush();
                }

                return;
            }
            if ('running' !== ($status['status'] ?? null)) {
                return;
            }
            if (null !== $resources) {
                self::assertRentalResources($config, $resources);
                self::assertRentalCloudInit($config, $settings, $ipConfig);
            }
            if ('booting' !== $phase) {
                $task->installationPhase('booting');
                $this->em->flush();
            }
            $network = VmGuestNetworkReport::fromQga($this->api->guestAgentRead($connection, $node, $id, 'network-get-interfaces'));
            if (!preg_match('/(?:^|,)[a-z0-9_]+=([0-9a-f]{2}(?::[0-9a-f]{2}){5})(?:,|$)/iD', $net0, $mac)) {
                return;
            }
            $expected = [];
            foreach ($leases as $lease) {
                $expected[] = $lease->getAddress().'/'.$lease->getPrefix();
            }
            foreach ($network['interfaces'] as $interface) {
                if (strtolower($mac[1]) !== $interface['mac']) {
                    continue;
                }
                $observed = [];
                $hasIpv4 = false;
                foreach ($interface['addresses'] as $address) {
                    $observed[] = $address['address'].'/'.$address['prefix'];
                    if ('ipv4' === $address['family'] && filter_var($address['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE)) {
                        $hasIpv4 = true;
                    }
                }
                if (([] === $expected ? $hasIpv4 : [] === array_diff($expected, $observed)) && $this->api->cloudInitBootFinished($connection, $node, $id)) {
                    $this->em->getConnection()->transactional(function () use ($task, $connection, $id, $settings, $config, $digest): void {
                        if (!$this->rentalCanProceed($task, true)) {
                            $task->finish(false, 'cancelled');
                        } else {
                            if (null !== $settings['customer_id']) {
                                $this->assign($task, $connection, $id, $settings['customer_id'], $config, $digest);
                            }
                            $task->finish(true, 'OK');
                            $this->audit->log(null, 'proxmox.installation_ready', ['cluster_id' => $task->getCluster()->getId(), 'vm_id' => $id, 'task_id' => $task->getId()]);
                        }
                        $this->em->flush();
                    });

                    return;
                }
            }
        } catch (\InvalidArgumentException $error) {
            $task->uncertain(match ($error->getMessage()) {
                'pve.error.rental_template_resources', 'pve.error.rental_resources_unconfirmed' => 'rental_resources_unconfirmed',
                'pve.error.rental_cloud_init_unconfirmed', 'pve.error.install_template' => 'rental_cloud_init_unconfirmed',
                'pve.error.identity' => 'identity_mismatch',
                'pve.error.ip_pool_network' => 'ip_pool_network',
                default => 'dispatch_unknown',
            });
            $this->em->flush();
        } catch (ProxmoxApiException $error) {
            // Connectivity, agent startup and transient provider errors are checked again later.
            if (in_array($error->statusCode, [401, 403], true)) {
                if ('queued' === $task->getState()) {
                    $task->finish(false, 'preflight_failed');
                } else {
                    $task->uncertain('permission_denied');
                }
                $this->em->flush();
            }
        } finally {
            $lock->release();
        }
    }

    /** @param RentalResources $resources */
    private function prepareRentalDisk(ProxmoxTask $task, ProxmoxConnection $connection, int $id, array $config, string $digest, array $resources): bool
    {
        $phase = $task->getInstallation()['phase'] ?? '';
        $disk = self::rentalSystemDisk($config, $resources);
        if ($disk['size_bytes'] === $resources['disk_gib'] * 1073741824) {
            if ('resizing' === $phase && null === $task->getUpid()) {
                // A cancelled or lost HTTP response might leave the storage task running.
                $path = '/nodes/'.$task->getNode();

                return !empty($this->api->permissions($connection, $path)[$path]['Sys.Audit'])
                    && [] === $this->api->activeTasks($connection, $task->getNode(), $id);
            }

            return true;
        }
        if ('cloning' !== $phase) {
            return false;
        }
        // Persist the phase before resizing so subsequent polls never send a duplicate write.
        $task->beginInstallationResize();
        $this->em->flush();
        try {
            $response = $this->api->growDisk($connection, $task->getNode(), $id, 'qemu', $disk['device'], $resources['disk_gib'], $digest);
        } catch (ProxmoxApiException $error) {
            if ($error->statusCode >= 400 && $error->statusCode < 500) {
                $task->finish(false, 'resize_rejected');
                $this->em->flush();
            }

            return false;
        }
        $upid = JsonMap::strings($response)['task'] ?? '';
        if ('' === $upid) {
            $task->installationPhase('configuring');
        } else {
            $task->dispatched($upid);
        }
        $this->em->flush();

        return false;
    }

    /** @param Installation $settings */
    private function dispatchQueued(ProxmoxTask $task, ProxmoxConnection $connection, int $target, array $settings): void
    {
        // A scheduler can only dispatch a reservation visible after its transaction commits.
        if ($this->em->getConnection()->isTransactionActive()) {
            return;
        }
        $node = $task->getNode();
        $source = $this->api->guestConfig($connection, $node, $task->getVmId());
        try {
            if (empty($source['template'])) {
                throw new \InvalidArgumentException('Template no longer available.');
            }
            self::assertTemplate($source);
            if (isset($settings['resources'])) {
                self::rentalSystemDisk($source, $settings['resources']);
            }
            foreach ($this->ipPools->leases($task) as $lease) {
                ProxmoxIpPools::assertPort(JsonMap::strings($source)['net0'] ?? '', $lease->getBridge(), $lease->getVlan());
            }
        } catch (\InvalidArgumentException) {
            $task->finish(false, 'preflight_failed');
            $this->em->flush();

            return;
        }
        $submit = $this->em->wrapInTransaction(function () use ($task): bool {
            $permitted = $this->rentalCanProceed($task, true);
            $this->em->refresh($task, LockMode::PESSIMISTIC_WRITE);
            if ('queued' !== $task->getState()) {
                return false;
            }
            if (!$permitted) {
                $task->finish(false, 'preflight_cancelled');

                return false;
            }
            $task->beginInstallationClone();

            return true;
        });
        if (!$submit) {
            return;
        }
        try {
            $response = $this->api->cloneTemplate($connection, $node, $task->getVmId(), $target, $settings['hostname'], 'qemu', self::marker($task->getRequestKey()));
            $upid = JsonMap::strings($response)['task'] ?? '';
            if ('' === $upid) {
                throw new \RuntimeException('Missing upstream task ID.');
            }
            $task->dispatched($upid);
        } catch (\RuntimeException $error) {
            if ($error instanceof ProxmoxApiException && $error->statusCode >= 400 && $error->statusCode < 500) {
                $task->finish(false, 'dispatch_failed');
            }
            // A lost response leaves the task submitting. The next poll inspects the reserved guest.
        }
        $this->em->flush();
    }

    private function rentalCanProceed(ProxmoxTask $task, bool $lock = false): bool
    {
        $id = $task->getInstallation()['rental_id'] ?? null;
        if (null === $id) {
            return true;
        }
        $contract = $this->em->find(ShopContract::class, $id);
        if (null === $contract) {
            return false;
        }
        $this->em->refresh($contract, $lock ? LockMode::PESSIMISTIC_WRITE : null);

        return 'provisioning' === $contract->getStatus() && 'proxmox' === $contract->getJobSystem() && (string) $task->getId() === $contract->getJobId();
    }

    private function assign(ProxmoxTask $task, ProxmoxConnection $connection, int $vmId, int $customerId, array $config, string $digest): void
    {
        $customer = $this->em->find(User::class, $customerId);
        if (!$customer?->isCustomer()) {
            throw new \InvalidArgumentException('pve.error.customer');
        }
        $tag = 'easywi-owned-'.substr($task->getRequestKey(), 0, 16);
        $existing = $this->em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $task->getCluster(), 'vmId' => $vmId]);
        if (null !== $existing && ($existing->getCustomer()->getId() !== $customerId || $existing->getIdentityTag() !== $tag)) {
            throw new \InvalidArgumentException('pve.error.identity');
        }
        $tags = array_filter(explode(';', JsonMap::strings($config)['tags'] ?? ''));
        foreach ($tags as $known) {
            if (str_starts_with($known, 'easywi-owned-') && $known !== $tag) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
        }
        if (!in_array($tag, $tags, true)) {
            $tags[] = $tag;
            $this->api->setGuestTags($connection, $task->getNode(), $vmId, 'qemu', implode(';', $tags), $digest);
        }
        if (null === $existing) {
            $this->em->persist(new ProxmoxGuestAssignment($task->getCluster(), $vmId, 'qemu', $customer, $tag));
        }
    }
}
