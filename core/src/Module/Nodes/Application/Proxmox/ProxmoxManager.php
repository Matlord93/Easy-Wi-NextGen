<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Module\Core\Application\AuditLoggerInterface;
use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Doctrine\EntityIdentifier;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\User;
use App\Module\Nodes\Application\VmGuestAgentReport;
use App\Module\Nodes\Application\VmProvisioningSettings;
use App\Module\Nodes\Application\VmRootPassword;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @psalm-import-type Installation from ProxmoxTask
 * @psalm-import-type RentalResources from ProxmoxTask
 * @psalm-import-type Reinstallation from ProxmoxTask
 */
final readonly class ProxmoxManager
{
    private const CUSTOMER_ACTIONS = [
        'start' => true,
        'shutdown' => true,
        'stop' => true,
        'reboot' => true,
        'snapshot-create' => true,
        'snapshot-delete' => true,
        'snapshot-rollback' => true,
        'backup' => true,
        'backup-restore' => true,
        'cloud-init' => true,
        'root-password' => true,
        'address-switch' => true,
    ];

    public function __construct(private ProxmoxApiClient $api, private SecretsCryptoInterface $crypto, private EntityManagerInterface $em, private AuditLoggerInterface $audit, private ProxmoxGuestAgent $guestAgent, private ProxmoxRrdUsage $rrd, private ProxmoxInstallation $installation, private ProxmoxIpPools $ipPools, private ProxmoxRentalLifecycle $rental, private ProxmoxReinstallation $reinstallation)
    {
    }

    /** @return array{nodes: list<array<array-key, mixed>>, guests: list<array<array-key, mixed>>} */
    public function overview(ProxmoxCluster $cluster): array
    {
        $connection = $cluster->connection($this->crypto);
        $resources = $this->api->clusterResources($connection);

        return [
            'nodes' => array_values(array_filter($resources, static fn (array $row): bool => ($row['type'] ?? '') === 'node')),
            'guests' => array_values(array_filter($resources, static fn (array $row): bool => in_array($row['type'] ?? '', ['qemu', 'lxc'], true))),
        ];
    }

    /** Resolve the current node from the cluster, including guests migrated since the page opened. */
    /** @return array<array-key, mixed> */
    public function guest(ProxmoxCluster $cluster, string $kind, int $vmId): array
    {
        return $this->findGuest($this->overview($cluster)['guests'], $kind, $vmId);
    }

    /**
     * @param list<array<array-key, mixed>> $guests
     * @return array<array-key, mixed>
     */
    private function findGuest(array $guests, string $kind, int $vmId): array
    {
        foreach ($guests as $guest) {
            if ((int) ($guest['vmid'] ?? 0) === $vmId && ($guest['type'] ?? '') === $kind) {
                return $guest;
            }
        }
        throw new \InvalidArgumentException('pve.error.guest_not_found');
    }

    /** @return array{server: array<array-key, mixed>, node: array<array-key, mixed>} */
    public function usage(ProxmoxCluster $cluster, string $kind, int $vmId, ?string $identityTag = null, bool $includeIo = true): array
    {
        $overview = $this->overview($cluster);
        $guest = $this->findGuest($overview['guests'], $kind, $vmId);
        if (null !== $identityTag && !in_array($identityTag, explode(';', (string) ($guest['tags'] ?? '')), true)) {
            throw new \InvalidArgumentException('pve.error.identity');
        }
        $node = [];
        foreach ($overview['nodes'] as $row) {
            if (null === $identityTag && ($row['node'] ?? '') === $guest['node']) {
                $node = $row;
                break;
            }
        }

        $connection = $cluster->connection($this->crypto);
        $config = null;
        if (null !== $identityTag) {
            $config = $this->api->guestConfig($connection, (string) $guest['node'], $vmId, $kind);
            if (!in_array($identityTag, explode(';', (string) ($config['tags'] ?? '')), true)) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
        }
        $server = $this->api->status($connection, (string) $guest['node'], $vmId, $kind) + $guest;
        if ($includeIo) {
            $server += $this->rrd->forGuest($connection, (string) $guest['node'], $kind, $vmId, $server);
        }
        $server['guest_filesystem'] = $this->filesystemUsage($connection, $server, $config);

        return ['server' => $server, 'node' => $node];
    }

    /** Collector already has the cluster inventory; do not refetch it for each VM. */
    /** @return array<array-key, mixed> */
    public function monitoringUsage(ProxmoxCluster $cluster, array $guest, ?string $identityTag): array
    {
        $connection = $cluster->connection($this->crypto);
        $node = (string) $guest['node'];
        $kind = (string) $guest['type'];
        $vmId = (int) $guest['vmid'];
        $config = null;
        if (null !== $identityTag) {
            $config = $this->api->guestConfig($connection, $node, $vmId, $kind);
            if (!in_array($identityTag, explode(';', (string) ($config['tags'] ?? '')), true)) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
        }

        return $guest + $this->rrd->forGuest($connection, $node, $kind, $vmId, $guest) + ['guest_filesystem' => $this->filesystemUsage($connection, $guest, $config)];
    }

    /** @return list<array{assignment: ProxmoxGuestAssignment, guest: array<array-key, mixed>|null}> */
    public function customerGuests(User $actor): array
    {
        $result = [];
        $clusters = [];
        foreach ($this->em->getRepository(ProxmoxGuestAssignment::class)->findBy(['customer' => $actor]) as $assignment) {
            $id = EntityIdentifier::require($assignment->getCluster()->getId());
            if (!array_key_exists($id, $clusters)) {
                try {
                    $clusters[$id] = $this->overview($assignment->getCluster())['guests'];
                } catch (\Throwable) {
                    $clusters[$id] = [];
                }
            }
            $match = null;
            foreach ($clusters[$id] as $guest) {
                if ((int) ($guest['vmid'] ?? 0) === $assignment->getVmId() && ($guest['type'] ?? '') === $assignment->getKind() && in_array($assignment->getIdentityTag(), explode(';', (string) ($guest['tags'] ?? '')), true)) {
                    $match = $guest;
                    break;
                }
            }
            $result[] = ['assignment' => $assignment, 'guest' => $match];
        }

        return $result;
    }

    /** @return array<array-key, mixed> */
    public function assignedGuest(ProxmoxGuestAssignment $assignment, User $actor): array
    {
        if ($assignment->getCustomer()->getId() !== $actor->getId()) {
            throw new \InvalidArgumentException('pve.error.forbidden');
        }
        $guest = $this->guest($assignment->getCluster(), $assignment->getKind(), $assignment->getVmId());
        if (!in_array($assignment->getIdentityTag(), explode(';', (string) ($guest['tags'] ?? '')), true)) {
            throw new \InvalidArgumentException('pve.error.identity');
        }

        return $guest;
    }

    /** A retained ownership tag prevents a reused VM ID from granting access to a replacement VM. */
    public function ownershipTag(ProxmoxCluster $cluster, string $kind, int $vmId, ?string $previousTag, bool $assign): string
    {
        $guest = $this->guest($cluster, $kind, $vmId);
        $connection = $cluster->connection($this->crypto);
        $config = $this->api->guestConfig($connection, (string) $guest['node'], $vmId, $kind);
        $tags = array_values(array_filter(explode(';', (string) ($config['tags'] ?? '')), static fn (string $tag): bool => '' !== $tag && $tag !== $previousTag));
        $tag = $assign ? 'easywi-owned-'.bin2hex(random_bytes(8)) : '';
        if ($assign) {
            $tags[] = $tag;
        }
        $this->api->setGuestTags($connection, (string) $guest['node'], $vmId, $kind, implode(';', $tags), JsonMap::strings($config)['digest'] ?? null);

        return $tag;
    }

    public function detail(ProxmoxCluster $cluster, string $kind, int $vmId, ?string $backupStorage = null, bool $customer = false, ?string $identityTag = null): array
    {
        $overview = $this->overview($cluster);
        $guest = $this->findGuest($overview['guests'], $kind, $vmId);
        $connection = $cluster->connection($this->crypto);
        $node = (string) $guest['node'];
        $storages = $this->api->storages($connection, $node);
        $backups = [];
        foreach ($storages as $storage) {
            $name = (string) ($storage['storage'] ?? '');
            if ($this->supports($storage, 'backup') && (!$customer || $name === $backupStorage)) {
                foreach ($this->api->storageContent($connection, $node, $name, 'backup', $vmId) as $backup) {
                    if ($this->backupMatches($backup, $vmId, $kind)) {
                        $backups[] = $backup + ['storage' => $name];
                    }
                }
            }
        }

        $configuration = null;
        $provisioning = null;
        if (!$customer || null !== $identityTag) {
            try {
                $configuration = $this->api->guestConfig($connection, $node, $vmId, $kind);
            } catch (\App\Module\Nodes\Domain\Proxmox\ProxmoxApiException $exception) {
                if ($customer) {
                    throw $exception;
                }
            }
            if ($configuration !== null && $customer && !in_array($identityTag, explode(';', (string) ($configuration['tags'] ?? '')), true)) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
            if ($configuration !== null && 'qemu' === $kind) {
                $provisioning = VmProvisioningSettings::proxmoxView($configuration, (string) ($guest['name'] ?? ''));
            }
        }

        $status = $this->api->status($connection, $node, $vmId, $kind);
        if (empty($guest['template'])) {
            $status += $this->rrd->forGuest($connection, $node, $kind, $vmId, $status);
        }
        $status['guest_filesystem'] = $this->filesystemUsage($connection, $status + $guest, $configuration);
        $agentOptions = explode(',', (string) ($configuration['agent'] ?? '0'));
        $guestAgentEnabled = in_array('1', $agentOptions, true) || in_array('enabled=1', $agentOptions, true);
        $addresses = [];
        $ipConfig = JsonMap::strings($configuration)['ipconfig0'] ?? '';
        foreach (explode(',', $ipConfig) as $setting) {
            [$key, $value] = array_pad(explode('=', $setting, 2), 2, '');
            $address = explode('/', $value, 2)[0];
            if (in_array($key, ['ip', 'ip6'], true) && false !== filter_var($address, FILTER_VALIDATE_IP)) {
                $addresses[] = $address;
            }
        }
        $addressChoices = [];
        $config = JsonMap::strings($configuration);
        if ($customer && 'qemu' === $kind && 'stopped' === ($status['status'] ?? null) && self::supportsAddressSwitch($config)
            && null !== ($origin = $this->addressOrigin($cluster, $node, $vmId, $config))) {
            $addressChoices = $this->ipPools->switchChoices($origin, $ipConfig);
        }

        return [
            'guest' => $guest, 'status' => $status, 'configuration' => $customer ? null : $configuration, 'provisioning' => $provisioning, 'staticAddresses' => $addresses, 'guestAgentEnabled' => $guestAgentEnabled, 'bootDevices' => !$customer && $configuration !== null ? VmProvisioningSettings::bootDevices($configuration) : [],
            'disks' => $configuration !== null ? ProxmoxDisks::attached($configuration, $kind) : [],
            'addressChoices' => $addressChoices,
            'extraDiskOptions' => !$customer && $configuration !== null ? ['devices' => ProxmoxExtraDisk::slots($configuration, $kind), 'storages' => array_values(array_filter($storages, fn (array $s): bool => $this->supports($s, 'qemu' === $kind ? 'images' : 'rootdir')))] : null,
            'nodeUsage' => $customer ? [] : (array_values(array_filter($overview['nodes'], static fn (array $row): bool => ($row['node'] ?? '') === $node))[0] ?? []),
            'snapshots' => $this->api->snapshots($connection, $node, $vmId, $kind),
            'storages' => $customer ? array_values(array_filter($storages, fn (array $s): bool => ($s['storage'] ?? '') === $backupStorage && $this->supports($s, 'backup'))) : $storages,
            'backups' => $backups,
            'isos' => !$customer && 'qemu' === $kind ? $this->content($connection, $node, $storages, 'iso') : [],
        ];
    }

    /** @param array<string, string> $configuration */
    private static function supportsAddressSwitch(array $configuration): bool
    {
        return '' !== ($configuration['digest'] ?? '') && '' !== ($configuration['ipconfig0'] ?? '')
            && '' === ($configuration['lock'] ?? '') && '' === ($configuration['cicustom'] ?? '')
            && 'nocloud' === ($configuration['citype'] ?? 'nocloud') && in_array($configuration['ostype'] ?? 'l26', ['l26', 'l24', 'other'], true)
            && VmProvisioningSettings::hasCloudInit($configuration);
    }

    /** @param array<string, string> $configuration */
    private function addressOrigin(ProxmoxCluster $cluster, string $node, int $vmId, array $configuration): ?ProxmoxTask
    {
        if (!preg_match('/^Easy-WI installation ([a-f0-9]{32})$/D', $configuration['description'] ?? '', $match)) {
            return null;
        }

        return $this->em->getRepository(ProxmoxTask::class)->findOneBy(['cluster' => $cluster, 'node' => $node, 'targetVmId' => $vmId, 'requestKey' => $match[1], 'action' => 'install-template', 'state' => 'succeeded']);
    }

    /**
     * @param array<array-key, mixed> $guest
     * @param array<array-key, mixed>|null $config
     * @return array<array-key, mixed>|null
     */
    private function filesystemUsage(ProxmoxConnection $connection, array $guest, ?array $config): ?array
    {
        if (($guest['type'] ?? '') !== 'qemu' || ($guest['status'] ?? '') !== 'running' || !empty($guest['template'])) {
            return null;
        }
        try {
            $node = (string) $guest['node'];
            $vmId = (int) $guest['vmid'];
            $config ??= $this->api->guestConfig($connection, $node, $vmId);
            $enabled = explode(',', (string) ($config['agent'] ?? '0'));
            if (!in_array('1', $enabled, true) && !in_array('enabled=1', $enabled, true)) {
                return null;
            }

            return $this->guestAgent->filesystemUsage($connection, $node, $vmId, (string) ($config['digest'] ?? ''));
        } catch (\App\Module\Nodes\Domain\Proxmox\ProxmoxApiException) {
            // Missing guest metrics must not hide CPU/RAM or break the VM page.
            return null;
        }
    }

    public function guestAgentStatus(ProxmoxCluster $cluster, string $kind, int $vmId, User $actor): array
    {
        if ('qemu' !== $kind || (!$actor->isAdmin() && !$actor->isCustomer())) {
            throw new \InvalidArgumentException('pve.error.forbidden');
        }
        $tag = null;
        if (!$actor->isAdmin()) {
            $assignment = $this->em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $cluster, 'vmId' => $vmId, 'kind' => $kind, 'customer' => $actor]);
            if (!$assignment) {
                throw new \InvalidArgumentException('pve.error.forbidden');
            }
            $this->assignedGuest($assignment, $actor);
            $tag = $assignment->getIdentityTag();
        }
        $guest = $this->guest($cluster, $kind, $vmId);
        $connection = $cluster->connection($this->crypto);
        $node = (string) $guest['node'];
        $config = $this->api->guestConfig($connection, $node, $vmId, $kind);
        if (null !== $tag && !in_array($tag, explode(';', (string) ($config['tags'] ?? '')), true)) {
            throw new \InvalidArgumentException('pve.error.identity');
        }
        if (($this->api->status($connection, $node, $vmId, $kind)['status'] ?? '') !== 'running') {
            return VmGuestAgentReport::observed('not_running');
        }
        $agent = explode(',', (string) ($config['agent'] ?? '0'));
        if (!(in_array('1', $agent, true) || in_array('enabled=1', $agent, true))) {
            return VmGuestAgentReport::observed('disabled');
        }

        return $this->guestAgent->probe($connection, $node, $vmId, (string) ($config['digest'] ?? ''));
    }

    public function creationOptions(ProxmoxCluster $cluster, string $node): array
    {
        $connection = $cluster->connection($this->crypto);
        $this->assertOnlineNode($connection, $node);
        $storages = $this->api->storages($connection, $node);

        return ['storages' => array_values(array_filter($storages, fn (array $s): bool => $this->supports($s, 'images'))), 'isos' => $this->content($connection, $node, $storages, 'iso'), 'bridges' => $this->api->networks($connection, $node)];
    }

    /** @return list<array<array-key, mixed>> */
    public function addressPoolBridges(ProxmoxCluster $cluster, string $node): array
    {
        $connection = $cluster->connection($this->crypto);
        $this->assertOnlineNode($connection, $node);

        return $this->api->networks($connection, $node);
    }

    public function releaseAddresses(ProxmoxTask $task, User $actor): void
    {
        $vmId = $task->getTargetVmId();
        if (!$actor->isAdmin() || $task->isActive() || null === $vmId || [] === $this->ipPools->leases($task)) {
            throw new \InvalidArgumentException('pve.error.ip_lease_release');
        }
        $cluster = $task->getCluster();
        $connection = $cluster->connection($this->crypto);
        if (!$this->api->isGuestIdFree($connection, $vmId)) {
            throw new \InvalidArgumentException('pve.error.ip_lease_release');
        }
        $this->em->getConnection()->transactional(function () use ($cluster, $task, $vmId, $actor): void {
            $this->em->refresh($cluster, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($task, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if ($task->isActive()) {
                throw new \InvalidArgumentException('pve.error.ip_lease_release');
            }
            $leases = $this->ipPools->leases($task);
            if ([] === $leases) {
                return;
            }
            $assignment = $this->em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $cluster, 'vmId' => $vmId]);
            if (null !== $assignment) {
                $this->em->remove($assignment);
            }
            foreach ($leases as $lease) {
                $this->em->remove($lease);
            }
            $this->audit->log($actor, 'proxmox.addresses_released', ['cluster_id' => $cluster->getId(), 'vm_id' => $vmId, 'task_id' => $task->getId()]);
            $this->em->flush();
        });
    }

    /** @return array{digest: string, images: array<int, string>, original: bool}|null */
    public function reinstallationChoices(ProxmoxGuestAssignment $assignment, User $actor, int $siteId): ?array
    {
        $guest = $this->assignedGuest($assignment, $actor);
        if ('qemu' !== $assignment->getKind() || 'stopped' !== ($guest['status'] ?? null)) {
            return null;
        }

        return $this->reinstallation->choices($assignment, (string) $guest['node'], $siteId);
    }

    public function queueReinstallation(ProxmoxGuestAssignment $assignment, User $actor, int $siteId, int $productId, string $digest, string $requestKey, ?string $installPasswordHash = null): ProxmoxTask
    {
        if (!$actor->isCustomer() || 'qemu' !== $assignment->getKind() || !preg_match('/^[a-f0-9]{32}$/D', $requestKey)) {
            throw new \InvalidArgumentException('pve.error.reinstall_unavailable');
        }
        $guest = $this->assignedGuest($assignment, $actor);
        $cluster = $assignment->getCluster();
        $node = (string) $guest['node'];
        $vmId = $assignment->getVmId();
        $repository = $this->em->getRepository(ProxmoxTask::class);
        $task = $repository->findOneBy(['cluster' => $cluster, 'requestKey' => $requestKey]);
        if (null === $task) {
            if ('stopped' !== ($guest['status'] ?? null)) {
                throw new \InvalidArgumentException('pve.error.resources_running');
            }
            if (null !== $repository->findOneBy(['cluster' => $cluster, 'targetLock' => $vmId])) {
                throw new \InvalidArgumentException('pve.error.task_active');
            }
            $this->assertOnlineNode($cluster->connection($this->crypto), $node);
            $plan = $this->reinstallation->plan($assignment, $node, $siteId, $productId, $digest, $installPasswordHash);
            [$task, $created] = $this->claim($cluster, $node, 'qemu', $vmId, 'reinstall', $requestKey, $actor, null, null, ['reinstall' => $plan], null, true);
            if ($created) {
                $this->audit->log($actor, 'proxmox.reinstallation_queued', ['cluster_id' => $cluster->getId(), 'vm_id' => $vmId, 'task_id' => $task->getId()]);
                $this->em->flush();
            }
        }
        $plan = $task->getReinstallation();
        if ('reinstall' !== $task->getAction() || $task->getActorId() !== $actor->getId() || ($plan['assignment_id'] ?? null) !== $assignment->getId()
            || ($plan['tag'] ?? null) !== $assignment->getIdentityTag() || ($plan['product_id'] ?? null) !== $productId || ($plan['digest'] ?? null) !== $digest
            || ($plan['install_password_hash'] ?? null) !== $installPasswordHash) {
            throw new \InvalidArgumentException('pve.error.request_key');
        }

        return $task;
    }

    public function passwordState(ProxmoxGuestAssignment $assignment, User $actor, string $operation): string
    {
        $id = filter_var($operation, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $task = $id === false ? null : $this->em->find(ProxmoxTask::class, $id);
        if (null === $task || $task->getCluster()->getId() !== $assignment->getCluster()->getId() || 'qemu' !== $task->getKind()) {
            return 'failed';
        }
        $matches = match ($task->getAction()) {
            'install-template' => $task->getTargetVmId() === $assignment->getVmId() && ($task->getInstallation()['customer_id'] ?? null) === $actor->getId()
                && $assignment->getIdentityTag() === 'easywi-owned-'.substr($task->getRequestKey(), 0, 16),
            'root-password', 'reinstall' => $task->getVmId() === $assignment->getVmId() && $task->getActorId() === $actor->getId(),
            default => false,
        };
        if (!$matches) {
            return 'failed';
        }
        $latest = $this->em->getRepository(ProxmoxTask::class)->findOneBy([
            'cluster' => $assignment->getCluster(), 'vmId' => $assignment->getVmId(), 'kind' => $assignment->getKind(),
            'action' => ['root-password', 'reinstall', 'snapshot-rollback', 'backup-restore'],
        ], ['id' => 'DESC']);
        if (null !== $latest && $latest->getId() > $id) {
            return 'failed';
        }

        return 'uncertain' !== $task->getState() && $task->isActive() ? 'pending' : ('OK' === $task->getResult() ? 'ready' : 'failed');
    }

    public function perform(ProxmoxCluster $cluster, string $kind, int $vmId, string $action, array $input, string $requestKey, User $actor, ?string $allowedBackupStorage = null): ProxmoxTask
    {
        $identityTag = null;
        $createsGuest = in_array($action, ['create-vm', 'clone', 'install-template'], true);
        $requestedId = $createsGuest && '' !== ($input['target_vm_id'] ?? '') ? $this->boundedInt($input['target_vm_id'], 100, 999999999) : null;
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestKey)) {
            throw new \InvalidArgumentException('pve.error.request_key');
        }
        if (!$actor->isAdmin()) {
            if (!$actor->isCustomer() || !isset(self::CUSTOMER_ACTIONS[$action])) {
                throw new \InvalidArgumentException('pve.error.forbidden');
            }
            $assignment = $this->em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $cluster, 'vmId' => $vmId, 'kind' => $kind, 'customer' => $actor]);
            if (!$assignment instanceof ProxmoxGuestAssignment) {
                throw new \InvalidArgumentException('pve.error.forbidden');
            }
            $this->assignedGuest($assignment, $actor);
            $allowedBackupStorage = $assignment->getBackupStorage();
            $identityTag = $assignment->getIdentityTag();
        }
        $existing = $this->em->getRepository(ProxmoxTask::class)->findOneBy(['cluster' => $cluster, 'requestKey' => $requestKey]);
        if ($existing instanceof ProxmoxTask) {
            if ($existing->getAction() !== $action || $existing->getKind() !== $kind || $existing->getVmId() !== $vmId || $existing->getActorId() !== $actor->getId() || (null !== $requestedId && $existing->getTargetVmId() !== $requestedId)) {
                throw new \InvalidArgumentException('pve.error.request_key');
            }

            return $existing;
        }
        $connection = $cluster->connection($this->crypto);
        $guest = 'create-vm' === $action ? ['node' => (string) ($input['node'] ?? ''), 'template' => 0] : $this->guest($cluster, $kind, $vmId);
        $node = (string) $guest['node'];
        $this->assertOnlineNode($connection, $node);
        $customer = !$actor->isAdmin();
        if (!empty($guest['template']) && !in_array($action, ['clone', 'install-template'], true)) {
            throw new \InvalidArgumentException('pve.error.template_action');
        }
        if (in_array($action, ['stop', 'reboot', 'snapshot-delete', 'snapshot-rollback', 'backup-delete', 'backup-restore', 'iso-mount'], true) && ($input['confirm'] ?? '') !== '1') {
            throw new \InvalidArgumentException('pve.error.confirm');
        }
        $repository = $this->em->getRepository(ProxmoxTask::class);
        if (0 !== $vmId) {
            $active = $repository->findOneBy(['cluster' => $cluster, 'targetLock' => $vmId])
                ?? $repository->findOneBy(['cluster' => $cluster, 'vmId' => $vmId, 'state' => ['queued', 'submitting', 'running']]);
            if ($active instanceof ProxmoxTask) {
                $this->refreshTask($active);
                if ($active->isActive()) {
                    throw new \InvalidArgumentException('pve.error.task_active');
                }
            }
        }
        $targetId = $createsGuest ? $this->chooseVmId($cluster, $connection, $node, $requestedId) : null;
        $installation = 'install-template' === $action ? ProxmoxInstallation::settings($input) : null;
        $customerId = $installation['customer_id'] ?? null;
        if (null !== $customerId && !$this->em->find(User::class, $customerId)?->isCustomer()) {
            throw new \InvalidArgumentException('pve.error.customer');
        }
        $poolNet = null;
        if (null !== $installation) {
            if ('qemu' !== $kind || empty($guest['template'])) {
                throw new \InvalidArgumentException('pve.error.install_template');
            }
            $template = $this->api->guestConfig($connection, $node, $vmId);
            ProxmoxInstallation::assertTemplate($template);
            if (null !== $targetId && 'required' === ($installation['prepare_root_password'] ?? null)) {
                $this->installation->assertRootPasswordPermission($connection, $targetId);
            }
            if (!in_array($input['address_mode'] ?? 'dhcp', ['dhcp', 'pool'], true)) {
                throw new \InvalidArgumentException('pve.error.ip_pool_network');
            }
            if ('pool' === ($input['address_mode'] ?? null)) {
                $poolNet = JsonMap::strings($template)['net0'] ?? '';
            }
        }
        $operation = $this->prepare($cluster, $connection, $node, $kind, $vmId, $action, $input, $guest, $customer ? $allowedBackupStorage : null, $customer, $identityTag, $targetId, $installation, $requestKey);

        // Claim the operation before contacting PVE. The unique request key prevents duplicate mutations.
        [$task, $claimed] = $this->claim($cluster, $node, $kind, $vmId, $action, $requestKey, $actor, $targetId, $requestedId, $installation, $poolNet);
        if (!$claimed) {
            return $task;
        }
        try {
            $result = $operation();
            $upid = JsonMap::strings($result)['task'] ?? null;
            if (null === $upid && !in_array($action, ['iso-mount', 'backup-delete', 'resources-update', 'cloud-init', 'boot-order', 'guest-agent', 'disk-grow', 'root-password', 'address-switch'], true) && !('disk-add' === $action && 'lxc' === $kind)) {
                throw new \RuntimeException('Missing upstream task ID.');
            }
            $task->dispatched(is_string($upid) ? $upid : null);
            $this->audit->log($actor, 'proxmox.action_dispatched', ['cluster_id' => $cluster->getId(), 'node' => $node, 'vm_id' => $vmId, 'action' => $action, 'task_id' => $task->getId()]);
        } catch (\Throwable $error) {
            if (in_array($action, ['address-switch', 'backup-restore'], true) && $error instanceof \InvalidArgumentException) {
                $task->finish(false, 'preflight_failed');
                $this->em->flush();
                throw $error;
            }
            if ($error instanceof \App\Module\Nodes\Domain\Proxmox\ProxmoxApiException && $error->statusCode >= 400 && $error->statusCode < 500) {
                $task->finish(false, in_array($error->statusCode, [401, 403], true) ? 'permission_denied' : 'dispatch_failed');
            } else {
                // A lost response or server error does not prove that a write was rejected.
                $task->uncertain();
            }
            $this->audit->log($actor, 'proxmox.action_failed', ['cluster_id' => $cluster->getId(), 'vm_id' => $vmId, 'action' => $action, 'request_path' => $error instanceof \App\Module\Nodes\Domain\Proxmox\ProxmoxApiException ? $error->requestPath : null]);
        }
        $this->em->flush();

        return $task;
    }

    /** @param RentalResources|null $resources */
    public function queueInstallation(ProxmoxCluster $cluster, int $vmId, array $input, string $requestKey, ?User $actor, ?int $rentalId = null, ?array $resources = null, ?string $expectedNode = null): ProxmoxTask
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestKey) || (null === $rentalId ? !$actor?->isAdmin() : (null !== $actor || null === $resources))) {
            throw new \InvalidArgumentException('pve.error.forbidden');
        }
        $requested = '' !== ($input['target_vm_id'] ?? '') ? $this->boundedInt($input['target_vm_id'], 100, 999999999) : null;
        $repository = $this->em->getRepository(ProxmoxTask::class);
        $previous = $repository->findOneBy(['cluster' => $cluster, 'requestKey' => $requestKey]);
        if (null !== $previous) {
            if ('install-template' !== $previous->getAction() || $previous->getVmId() !== $vmId || $previous->getActorId() !== $actor?->getId()
                || ($previous->getInstallation()['rental_id'] ?? null) !== $rentalId || (null !== $requested && $requested !== $previous->getTargetVmId())) {
                throw new \InvalidArgumentException('pve.error.request_key');
            }

            return $previous;
        }
        $settings = ProxmoxInstallation::settings($input);
        if (null !== $rentalId && null !== $resources) {
            $settings['rental_id'] = $rentalId;
            $settings['resources'] = $resources;
            $settings['prepare_root_password'] = 'if-supported';
        }
        $customer = $settings['customer_id'];
        if (null !== $customer && !$this->em->find(User::class, $customer)?->isCustomer()) {
            throw new \InvalidArgumentException('pve.error.customer');
        }
        $connection = $cluster->connection($this->crypto);
        [$node, $source] = $this->installationSource($cluster, $connection, $vmId, $expectedNode);
        if (null !== $resources) {
            ProxmoxInstallation::rentalSystemDisk($source, $resources);
        }
        $poolNet = match ($input['address_mode'] ?? 'dhcp') {
            'pool' => JsonMap::strings($source)['net0'] ?? '',
            'dhcp' => null,
            default => throw new \InvalidArgumentException('pve.error.ip_pool_network'),
        };
        $target = $this->chooseVmId($cluster, $connection, $node, $requested);
        if ('required' === ($settings['prepare_root_password'] ?? null)) {
            $this->installation->assertRootPasswordPermission($connection, $target);
        }
        [$task] = $this->claim($cluster, $node, 'qemu', $vmId, 'install-template', $requestKey, $actor, $target, $requested, $settings, $poolNet, true);

        return $task;
    }

    /** @param RentalResources $resources */
    public function validateRentalTemplate(ProxmoxCluster $cluster, int $vmId, string $expectedNode, string $addressMode, array $resources): void
    {
        $connection = $cluster->connection($this->crypto);
        [$node, $source] = $this->installationSource($cluster, $connection, $vmId, $expectedNode);
        ProxmoxInstallation::rentalSystemDisk($source, $resources);
        if ('pool' === $addressMode) {
            $net0 = JsonMap::strings($source)['net0'] ?? '';
            foreach ($this->ipPools->pools($cluster, $node) as $pool) {
                if ($pool->isEnabled()) {
                    ProxmoxIpPools::assertPort($net0, $pool->getBridge(), $pool->getVlan());
                }
            }
        }
    }

    /** @return array{string, array<array-key, mixed>} */
    private function installationSource(ProxmoxCluster $cluster, ProxmoxConnection $connection, int $vmId, ?string $expectedNode): array
    {
        $guest = $this->guest($cluster, 'qemu', $vmId);
        $node = JsonMap::strings($guest)['node'] ?? '';
        if (empty($guest['template']) || (null !== $expectedNode && $node !== $expectedNode)) {
            throw new \InvalidArgumentException('pve.error.install_template');
        }
        $this->assertOnlineNode($connection, $node);
        $source = $this->api->guestConfig($connection, $node, $vmId);
        ProxmoxInstallation::assertTemplate($source);

        return [$node, $source];
    }

    private function chooseVmId(ProxmoxCluster $cluster, ProxmoxConnection $connection, string $node, ?int $requested): int
    {
        $clusterId = $cluster->getId();
        $db = $this->em->getConnection();
        $legacy = $db->fetchOne("SELECT id FROM proxmox_tasks WHERE cluster_id = ? AND target_vm_id IS NULL AND action IN ('create-vm', 'clone') AND state IN ('submitting', 'running', 'uncertain')", [$clusterId]);
        if (false !== $legacy) {
            throw new \InvalidArgumentException('pve.error.task_active');
        }
        $occupied = [];
        foreach (array_map('intval', $db->fetchFirstColumn('SELECT target_vm_id FROM proxmox_tasks t WHERE t.cluster_id = ? AND t.target_vm_id IS NOT NULL AND (t.target_lock IS NOT NULL OR EXISTS (SELECT 1 FROM proxmox_ip_leases l WHERE l.task_id = t.id))', [$clusterId])) as $id) {
            $occupied[$id] = true;
        }
        $range = $cluster->vmIdRange($node);
        $candidate = $requested ?? $range['first'] ?? $this->api->nextVmId($connection);
        if (null !== $requested && (($range !== null && ($requested < $range['first'] || $requested > $range['last'])) || $requested !== $cluster->nextAllowedVmId($node, $requested))) {
            throw new \InvalidArgumentException('pve.error.vm_id_range');
        }
        if (null === $requested && null === $range && !isset($occupied[$candidate]) && $candidate === $cluster->nextAllowedVmId($node, $candidate)) {
            return $candidate;
        }
        foreach ($this->overview($cluster)['guests'] as $guest) {
            $occupied[(int) ($guest['vmid'] ?? 0)] = true;
        }
        if (null !== $requested) {
            if (isset($occupied[$requested])) {
                throw new \InvalidArgumentException('pve.error.vm_id_taken');
            }

            return $requested;
        }
        $last = $range['last'] ?? 999999999;
        for ($candidate = $cluster->nextAllowedVmId($node, $candidate); $candidate <= $last; $candidate = $cluster->nextAllowedVmId($node, $candidate + 1)) {
            if (!isset($occupied[$candidate])) {
                return $candidate;
            }
        }

        throw new \InvalidArgumentException('pve.error.vm_id_exhausted');
    }

    public function refreshTask(ProxmoxTask $task): void
    {
        if (!$task->isActive() || 'uncertain' === $task->getState()) {
            return;
        }
        if ('install-template' === $task->getAction()) {
            $this->installation->advance($task);

            return;
        }
        if ('reinstall' === $task->getAction()) {
            $this->reinstallation->advance($task);

            return;
        }
        if (null !== $task->getOriginTaskId()) {
            $this->rental->advance($task);

            return;
        }
        $upid = $task->getUpid();
        if ($upid === null) {
            if ($task->getCreatedAt() < new \DateTimeImmutable('-2 minutes')) {
                $task->uncertain();
                $this->em->flush();
            }

            return;
        }
        $status = $this->api->taskStatus($task->getCluster()->connection($this->crypto), $task->getNode(), $upid);
        if (($status['status'] ?? '') === 'stopped') {
            $success = ($status['exitstatus'] ?? '') === 'OK';
            $task->finish($success, $success ? 'OK' : 'upstream_failed');
            $this->audit->log(null, 'proxmox.action_finished', ['cluster_id' => $task->getCluster()->getId(), 'vm_id' => $task->getVmId(), 'task_id' => $task->getId(), 'success' => $success]);
            $this->em->flush();
        }
    }

    /** Resolve bookkeeping only after explicit operator review and fresh upstream checks. */
    public function reviewTask(ProxmoxTask $task, User $actor, bool $success, string $note): void
    {
        if (!$actor->isAdmin() || 'uncertain' !== $task->getState() || ($success && null !== $task->getOriginTaskId()) || mb_strlen(trim($note)) < 10 || mb_strlen($note) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note)) {
            throw new \InvalidArgumentException('pve.error.review');
        }
        $installation = $task->getInstallation();
        if ($success && isset($installation['rental_id'])) {
            throw new \InvalidArgumentException('pve.error.review_rental_installation');
        }
        if ($success && 'reinstall' === $task->getAction()) {
            $this->reinstallation->confirmRemoval($task, $actor, trim($note));

            return;
        }
        $connection = $task->getCluster()->connection($this->crypto);
        $overview = $this->overview($task->getCluster());
        $checkedSource = false;
        foreach ($overview['nodes'] as $node) {
            $name = (string) ($node['node'] ?? '');
            if ('online' !== ($node['status'] ?? '') || '' === $name) {
                throw new \InvalidArgumentException('pve.error.review_nodes');
            }
            $checkedSource = $checkedSource || $name === $task->getNode();
            // Without Sys.Audit the provider may silently omit another user's tasks.
            if (!$this->api->hasPrivilege($connection, '/nodes/'.$name, 'Sys.Audit')) {
                throw new \InvalidArgumentException('pve.error.review_permissions');
            }
            if ([] !== $this->api->activeTasks($connection, $name, $task->getTargetVmId() ?? ($task->getVmId() ?: null))) {
                throw new \InvalidArgumentException('pve.error.review_active');
            }
            if ($name === $task->getNode() && 'cloning' === ($installation['phase'] ?? null) && [] !== $this->api->activeTasks($connection, $name, $task->getVmId())) {
                throw new \InvalidArgumentException('pve.error.review_active');
            }
        }
        if (!$checkedSource) {
            throw new \InvalidArgumentException('pve.error.review_nodes');
        }
        foreach ($overview['guests'] as $guest) {
            $reviewId = $task->getTargetVmId() ?? $task->getVmId();
            if (($guest['type'] ?? '') === $task->getKind() && (int) ($guest['vmid'] ?? 0) === $reviewId) {
                $config = $this->api->guestConfig($connection, (string) $guest['node'], $reviewId, $task->getKind());
                if (!empty($config['lock'])) {
                    throw new \InvalidArgumentException('pve.error.review_active');
                }
            }
        }
        $this->em->wrapInTransaction(function () use ($task, $actor, $success, $note): void {
            $this->em->refresh($task, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            if ('uncertain' !== $task->getState()) {
                // Another reviewed request won; do not overwrite its outcome.
                return;
            }
            $task->finish($success, $success ? 'reviewed_success' : 'reviewed_failure');
            $this->audit->log($actor, 'proxmox.task_reviewed', ['task_id' => $task->getId(), 'cluster_id' => $task->getCluster()->getId(), 'success' => $success, 'operator_note' => trim($note)]);
        });
    }

    /**
     * @param Installation|array{reinstall: Reinstallation}|null $installation
     * @return array{ProxmoxTask, bool}
     */
    private function claim(ProxmoxCluster $cluster, string $node, string $kind, int $vmId, string $action, string $requestKey, ?User $actor, ?int $targetId, ?int $requestedId, ?array $installation, ?string $poolNet, bool $queued = false): array
    {
        $connection = $this->em->getConnection();
        try {
            // DBAL keeps the entity manager usable if a concurrent unique claim wins.
            $taskId = $connection->transactional(function () use ($connection, $cluster, $node, $kind, $vmId, $action, $requestKey, $actor, $targetId, $installation, $poolNet, $queued): int {
                if (null !== $targetId || 'backup-restore' === $action) {
                    $this->em->refresh($cluster, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                }
                $connection->insert('proxmox_tasks', ['cluster_id' => $cluster->getId(), 'node' => $node, 'kind' => $kind, 'vm_id' => $vmId, 'action' => $action, 'request_key' => $requestKey, 'state' => $queued ? 'queued' : 'submitting', 'guest_lock' => 'create-vm' === $action ? 'new:'.($targetId ?? throw new \LogicException('Missing VM ID.')) : $kind.':'.$vmId, 'target_vm_id' => $targetId, 'target_lock' => $targetId, 'installation' => $installation, 'actor_id' => $actor?->getId(), 'created_at' => new \DateTimeImmutable()], ['installation' => \Doctrine\DBAL\Types\Types::JSON, 'created_at' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE]);
                $id = (int) $connection->lastInsertId();
                if (null !== $targetId && false !== $connection->fetchOne('SELECT 1 FROM proxmox_tasks t JOIN proxmox_ip_leases l ON l.task_id = t.id WHERE t.cluster_id = ? AND t.target_vm_id = ? LIMIT 1', [$cluster->getId(), $targetId])) {
                    throw new \InvalidArgumentException('pve.error.vm_id_taken');
                }
                if (null !== $poolNet) {
                    $this->ipPools->reserve($id, $cluster, $node, $poolNet);
                }

                return $id;
            });
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $existing = $this->em->getRepository(ProxmoxTask::class)->findOneBy(['cluster' => $cluster, 'requestKey' => $requestKey]);
            if (!$existing instanceof ProxmoxTask) {
                throw new \InvalidArgumentException('pve.error.task_active');
            }
            if ($existing->getAction() !== $action || $existing->getKind() !== $kind || $existing->getVmId() !== $vmId || $existing->getActorId() !== $actor?->getId() || (null !== $requestedId && $existing->getTargetVmId() !== $requestedId)) {
                throw new \InvalidArgumentException('pve.error.request_key');
            }

            return [$existing, false];
        }
        $task = $this->em->find(ProxmoxTask::class, $taskId);
        if (!$task instanceof ProxmoxTask) {
            throw new \RuntimeException('Unable to read claimed Proxmox operation.');
        }

        return [$task, true];
    }

    /**
     * @param Installation|null $installation
     * @return \Closure(): array<array-key, mixed>
     */
    private function prepare(ProxmoxCluster $cluster, ProxmoxConnection $connection, string $node, string $kind, int $vmId, string $action, array $input, array $guest, ?string $allowedStorage, bool $customer, ?string $identityTag, ?int $targetId, ?array $installation, string $requestKey): \Closure
    {
        if ('disk-add' === $action) {
            if ($customer) {
                throw new \InvalidArgumentException('pve.error.forbidden');
            }
            if (($this->api->status($connection, $node, $vmId, $kind)['status'] ?? '') !== 'stopped') {
                throw new \InvalidArgumentException('pve.error.resources_running');
            }
            $config = $this->api->guestConfig($connection, $node, $vmId, $kind);
            $spec = ProxmoxExtraDisk::specification($config, $kind, $input);
            $allowed = array_filter($this->api->storages($connection, $node), fn (array $storage): bool => ($storage['storage'] ?? '') === $spec['storage'] && $this->supports($storage, 'qemu' === $kind ? 'images' : 'rootdir'));
            if ([] === $allowed) {
                throw new \InvalidArgumentException('pve.error.storage');
            }
            foreach ($this->api->snapshots($connection, $node, $vmId, $kind) as $snapshot) {
                if (($snapshot['name'] ?? '') !== 'current') {
                    throw new \InvalidArgumentException('pve.error.disk_snapshots');
                }
            }

            return fn (): array => $this->api->addDisk($connection, $node, $vmId, $kind, $spec);
        }
        if ('disk-grow' === $action) {
            if ($customer) {
                throw new \InvalidArgumentException('pve.error.forbidden');
            }
            if ('1' !== ($input['confirm'] ?? '')) {
                throw new \InvalidArgumentException('pve.error.confirm');
            }
            if (($this->api->status($connection, $node, $vmId, $kind)['status'] ?? '') !== 'stopped') {
                throw new \InvalidArgumentException('pve.error.resources_running');
            }
            $config = $this->api->guestConfig($connection, $node, $vmId, $kind);
            $disk = JsonMap::strings($input)['disk'] ?? '';
            $size = $this->boundedInt($input['size_gib'] ?? '', 1, 65536);
            $expected = $this->boundedInt($input['expected_bytes'] ?? '', 1, 70368744177664);
            $attached = array_column(ProxmoxDisks::attached($config, $kind), null, 'device');
            $digest = JsonMap::strings($config)['digest'] ?? null;
            if (!isset($attached[$disk]) || null === $digest || '' === $digest || !empty($config['lock'])) {
                throw new \InvalidArgumentException('pve.error.disk_unavailable');
            }
            if ($attached[$disk]['size_bytes'] !== $expected || ($input['expected_digest'] ?? null) !== $digest) {
                throw new \InvalidArgumentException('pve.error.disk_changed');
            }
            if ($size * 1073741824 <= $expected) {
                throw new \InvalidArgumentException('pve.error.disk_grow_only');
            }
            foreach ($this->api->snapshots($connection, $node, $vmId, $kind) as $snapshot) {
                if (($snapshot['name'] ?? '') !== 'current') {
                    throw new \InvalidArgumentException('pve.error.disk_snapshots');
                }
            }

            return fn (): array => $this->api->growDisk($connection, $node, $vmId, $kind, $disk, $size, $digest);
        }
        if ('root-password' === $action) {
            $hash = JsonMap::strings($input)['password_hash'] ?? '';
            if ('qemu' !== $kind || !$customer || ($input['confirm'] ?? '') !== '1' || !preg_match(VmRootPassword::HASH_PATTERN, $hash)) {
                throw new \InvalidArgumentException('pve.error.forbidden');
            }
            if (($this->api->status($connection, $node, $vmId)['status'] ?? null) !== 'running') {
                throw new \InvalidArgumentException('pve.error.unavailable');
            }

            return function () use ($connection, $node, $vmId, $identityTag, $hash): array {
                $config = $this->api->guestConfig($connection, $node, $vmId, current: true);
                if (null === $identityTag || !in_array($identityTag, explode(';', JsonMap::strings($config)['tags'] ?? ''), true)) {
                    throw new \InvalidArgumentException('pve.error.identity');
                }

                return $this->api->setRootPasswordHash($connection, $node, $vmId, $hash);
            };
        }
        if ('address-switch' === $action) {
            $address = JsonMap::strings($input)['expected_address'] ?? '';
            if (!$customer || 'qemu' !== $kind || null === $identityTag || '1' !== ($input['confirm'] ?? '') || !filter_var($address, FILTER_VALIDATE_IP)) {
                throw new \InvalidArgumentException('vm_config.address_switch_unavailable');
            }

            return fn (): array => $this->switchAddress($cluster, $connection, $node, $vmId, $identityTag, $address);
        }
        if (in_array($action, ['cloud-init', 'boot-order', 'guest-agent'], true)) {
            if ('qemu' !== $kind || ($customer && 'cloud-init' !== $action)) {
                throw new \InvalidArgumentException('pve.error.forbidden');
            }
            if (($this->api->status($connection, $node, $vmId, $kind)['status'] ?? '') !== 'stopped') {
                throw new \InvalidArgumentException('pve.error.resources_running');
            }
            $config = $this->api->guestConfig($connection, $node, $vmId, $kind);
            $digest = JsonMap::strings($config)['digest'] ?? null;
            if ($customer && ($identityTag === null || $identityTag === '' || !in_array($identityTag, explode(';', (string) ($config['tags'] ?? '')), true))) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
            if ('cloud-init' === $action) {
                if (!VmProvisioningSettings::hasCloudInit($config) || !empty($config['cicustom'])) {
                    throw new \InvalidArgumentException('vm_config.no_cloud_init');
                }
                $hostname = VmProvisioningSettings::hostname($input['hostname'] ?? '');
                $username = trim(JsonMap::strings($input)['username'] ?? '');
                if (!preg_match('/^[a-z_][a-z0-9_-]{0,30}$/D', $username)) {
                    throw new \InvalidArgumentException('vm_config.invalid_user');
                }
                $keys = VmProvisioningSettings::publicKeys($input['ssh_keys'] ?? '');

                return fn (): array => $this->api->updateProvisioning($connection, $node, $vmId, $hostname, $username, $keys, $digest);
            }
            if ('boot-order' === $action) {
                $raw = trim(JsonMap::strings($input)['boot_order'] ?? '');
                $devices = explode(';', $raw);
                if (count($devices) > 8 || count(array_unique($devices)) !== count($devices) || array_diff($devices, VmProvisioningSettings::bootDevices($config))) {
                    throw new \InvalidArgumentException('vm_config.invalid_boot');
                }

                return fn (): array => $this->api->updateBootOrder($connection, $node, $vmId, $devices, $digest);
            }
            $options = array_filter(explode(',', (string) ($config['agent'] ?? '')),
                static fn (string $value): bool => '' !== $value && !in_array($value, ['0', '1'], true) && !str_starts_with($value, 'enabled='));
            array_unshift($options, 'enabled=1');

            return fn (): array => $this->api->enableGuestAgent($connection, $node, $vmId, implode(',', $options), $digest);
        }
        if ('resources-update' === $action) {
            if ($customer) {
                throw new \InvalidArgumentException('pve.error.forbidden');
            }
            $cores = $this->boundedInt($input['cores'] ?? '', 1, 128);
            $memory = $this->boundedInt($input['memory'] ?? '', 128, 1048576);
            if (($this->api->status($connection, $node, $vmId, $kind)['status'] ?? '') !== 'stopped') {
                throw new \InvalidArgumentException('pve.error.resources_running');
            }
            $config = $this->api->guestConfig($connection, $node, $vmId, $kind);

            return fn (): array => $this->api->updateResources($connection, $node, $vmId, $kind, $cores, $memory, JsonMap::strings($config)['digest'] ?? null);
        }
        if (in_array($action, ['start', 'stop', 'shutdown', 'reboot'], true)) {
            return fn (): array => $this->api->changePowerState($connection, $node, $vmId, $action, $kind);
        }
        if (str_starts_with($action, 'snapshot-')) {
            $name = (string) ($input['snapshot'] ?? '');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/D', $name) || 'current' === $name) {
                throw new \InvalidArgumentException('pve.error.snapshot');
            }
            if ('snapshot-create' !== $action) {
                $snapshots = $this->api->snapshots($connection, $node, $vmId, $kind);
                if (!in_array($name, array_column($snapshots, 'name'), true)) {
                    throw new \InvalidArgumentException('pve.error.snapshot');
                }
            }

            return match ($action) {
                'snapshot-create' => fn (): array => $this->api->createSnapshot($connection, $node, $vmId, $name, (string) ($input['description'] ?? ''), $kind),
                'snapshot-delete' => fn (): array => $this->api->deleteSnapshot($connection, $node, $vmId, $name, $kind),
                'snapshot-rollback' => fn (): array => $this->api->rollbackSnapshot($connection, $node, $vmId, $name, $kind),
                default => throw new \InvalidArgumentException('pve.error.action'),
            };
        }
        if (in_array($action, ['backup', 'backup-delete', 'backup-restore'], true)) {
            $storage = (string) ($input['storage'] ?? '');
            if ($customer && (null === $allowedStorage || $storage !== $allowedStorage)) {
                throw new \InvalidArgumentException('pve.error.storage');
            }
            $this->assertStorage($connection, $node, $storage, 'backup');
            if ('backup' === $action) {
                $mode = (string) ($input['mode'] ?? 'snapshot');
                if (!in_array($mode, ['snapshot', 'suspend', 'stop'], true)) {
                    throw new \InvalidArgumentException('pve.error.backup_mode');
                }

                return fn (): array => $this->api->createBackup($connection, $node, $vmId, $storage, $mode);
            }
            $volume = (string) ($input['volume'] ?? '');
            $found = false;
            foreach ($this->api->storageContent($connection, $node, $storage, 'backup', $vmId) as $backup) {
                if (($backup['volid'] ?? '') === $volume && $this->backupMatches($backup, $vmId, $kind)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \InvalidArgumentException('pve.error.backup');
            }
            if ('backup-delete' === $action) {
                return fn (): array => $this->api->deleteBackup($connection, $node, $storage, $volume);
            }
            // Restore only the selected guest, and only while stopped; never silently replace another VM.
            if (($this->api->status($connection, $node, $vmId, $kind)['status'] ?? '') !== 'stopped') {
                throw new \InvalidArgumentException('pve.error.restore_running');
            }
            if ($customer) {
                return fn (): array => $this->restoreCustomerBackup($connection, $node, $kind, $vmId, $volume, $identityTag ?? '');
            }
            $target = (string) ($input['target_storage'] ?? '');
            $this->assertStorage($connection, $node, $target, 'qemu' === $kind ? 'images' : 'rootdir');

            return fn (): array => $this->api->restoreBackup($connection, $node, $vmId, $volume, $kind, $target, true);
        }
        if ('iso-mount' === $action) {
            if ('qemu' !== $kind) {
                throw new \InvalidArgumentException('pve.error.action');
            }
            $volume = $this->assertIso($connection, $node, (string) ($input['iso'] ?? ''));

            $config = $this->api->guestConfig($connection, $node, $vmId, 'qemu');
            $slot = null;
            foreach (['ide2', 'ide0', 'ide1', 'ide3'] as $candidate) {
                $drive = (string) ($config[$candidate] ?? '');
                if ('' === $drive || (str_contains($drive, 'media=cdrom') && false === stripos($drive, 'cloudinit'))) {
                    $slot = $candidate;
                    break;
                }
            }
            if (null === $slot) {
                throw new \InvalidArgumentException('pve.error.cdrom_slot');
            }

            return fn (): array => $this->api->mountIso($connection, $node, $vmId, $volume, $slot, JsonMap::strings($config)['digest'] ?? null);
        }
        if ('install-template' === $action) {
            if ('qemu' !== $kind || empty($guest['template']) || null === $installation || null === $targetId) {
                throw new \InvalidArgumentException('pve.error.install_template');
            }

            return fn (): array => $this->api->cloneTemplate($connection, $node, $vmId, $targetId, $installation['hostname'], 'qemu', ProxmoxInstallation::marker($requestKey));
        }
        if ('clone' === $action) {
            if (empty($guest['template'])) {
                throw new \InvalidArgumentException('pve.error.template_action');
            }
            $name = $this->guestName((string) ($input['name'] ?? ''));
            $newId = $targetId ?? throw new \LogicException('Missing clone destination ID.');

            return fn (): array => $this->api->cloneTemplate($connection, $node, $vmId, $newId, $name, $kind);
        }
        if ('create-vm' === $action) {
            $name = $this->guestName((string) ($input['name'] ?? ''));
            $iso = $this->assertIso($connection, $node, (string) ($input['iso'] ?? ''));
            $storage = (string) ($input['storage'] ?? '');
            $this->assertStorage($connection, $node, $storage, 'images');
            $cores = $this->boundedInt($input['cores'] ?? '', 1, 128);
            $memory = $this->boundedInt($input['memory'] ?? '', 256, 1048576);
            $disk = $this->boundedInt($input['disk'] ?? '', 1, 65536);
            $bridge = (string) ($input['bridge'] ?? '');
            if (!in_array($bridge, array_column($this->api->networks($connection, $node), 'iface'), true) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $bridge)) {
                throw new \InvalidArgumentException('pve.error.bridge');
            }
            $id = $targetId ?? throw new \LogicException('Missing creation destination ID.');
            $config = ['vmid' => $id, 'name' => $name, 'cores' => $cores, 'memory' => $memory, 'scsihw' => 'virtio-scsi-single', 'scsi0' => $storage.':'.$disk, 'ide2' => $iso.',media=cdrom', 'net0' => 'virtio,bridge='.$bridge, 'boot' => 'order=ide2;scsi0', 'ostype' => 'l26', 'start' => 0];

            return fn (): array => $this->api->createVm($connection, $node, $config);
        }
        throw new \InvalidArgumentException('pve.error.action');
    }

    private function assertOnlineNode(ProxmoxConnection $connection, string $node): void
    {
        foreach ($this->api->nodes($connection) as $entry) {
            if (($entry['node'] ?? '') === $node && ($entry['status'] ?? '') === 'online') {
                return;
            }
        }
        throw new \InvalidArgumentException('pve.error.node_offline');
    }

    private function supports(array $storage, string $content): bool
    {
        return !empty($storage['active']) && in_array($content, explode(',', (string) ($storage['content'] ?? '')), true);
    }

    private function assertStorage(ProxmoxConnection $connection, string $node, string $storage, string $content): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $storage)) {
            throw new \InvalidArgumentException('pve.error.storage');
        }
        foreach ($this->api->storages($connection, $node) as $entry) {
            if (($entry['storage'] ?? '') === $storage && $this->supports($entry, $content)) {
                return;
            }
        }
        throw new \InvalidArgumentException('pve.error.storage');
    }

    /**
     * @param list<array<array-key, mixed>> $storages
     * @return list<array<array-key, mixed>>
     */
    private function content(ProxmoxConnection $connection, string $node, array $storages, string $type): array
    {
        $items = [];
        foreach ($storages as $storage) {
            if ($this->supports($storage, $type)) {
                array_push($items, ...$this->api->storageContent($connection, $node, (string) $storage['storage'], $type));
            }
        }

        return $items;
    }

    private function assertIso(ProxmoxConnection $connection, string $node, string $volume): string
    {
        if (!preg_match('/^([A-Za-z0-9_-]{1,64}):iso\/[A-Za-z0-9_. -]+\.iso$/Di', $volume, $matches)) {
            throw new \InvalidArgumentException('pve.error.iso');
        }
        $this->assertStorage($connection, $node, $matches[1], 'iso');
        if (!in_array($volume, array_column($this->api->storageContent($connection, $node, $matches[1], 'iso'), 'volid'), true)) {
            throw new \InvalidArgumentException('pve.error.iso');
        }

        return $volume;
    }

    /** @return array<array-key, mixed> */
    private function switchAddress(ProxmoxCluster $cluster, ProxmoxConnection $connection, string $node, int $vmId, string $identityTag, string $expectedAddress): array
    {
        if ('stopped' !== ($this->api->status($connection, $node, $vmId)['status'] ?? null)) {
            throw new \InvalidArgumentException('pve.error.resources_running');
        }
        $config = JsonMap::strings($this->api->guestConfig($connection, $node, $vmId, current: true));
        if (!in_array($identityTag, explode(';', $config['tags'] ?? ''), true)) {
            throw new \InvalidArgumentException('pve.error.identity');
        }
        $digest = $config['digest'] ?? '';
        $origin = $this->addressOrigin($cluster, $node, $vmId, $config);
        if ('' === $digest || null === $origin || !self::supportsAddressSwitch($config) || $this->api->hasPendingGuestConfiguration($connection, $node, $vmId)) {
            throw new \InvalidArgumentException('vm_config.address_switch_unavailable');
        }
        if (!$this->api->hasPrivilege($connection, '/nodes/'.$node, 'Sys.Audit')
            || !$this->api->hasPrivilege($connection, '/vms/'.$vmId, 'VM.Config.Cloudinit')) {
            throw new \InvalidArgumentException('pve.error.permissions');
        }
        if ([] !== $this->api->activeTasks($connection, $node, $vmId)) {
            throw new \InvalidArgumentException('pve.error.task_active');
        }

        // Commit the second reservation before sending a configuration write.
        // Both addresses stay with this installation after a lost API response.
        $next = $this->ipPools->reserveSwitch($origin, $config['net0'] ?? '', $config['ipconfig0'] ?? '', $expectedAddress);
        $result = $this->api->updatePoolAddress($connection, $node, $vmId, $next, $digest);
        $fresh = JsonMap::strings($this->api->guestConfig($connection, $node, $vmId, current: true));
        if (!in_array($identityTag, explode(';', $fresh['tags'] ?? ''), true)
            || ProxmoxIpPools::networkSettings($next) !== ProxmoxIpPools::networkSettings($fresh['ipconfig0'] ?? '')) {
            throw new \RuntimeException('Proxmox did not confirm the reserved address switch.');
        }

        return $result;
    }

    /** @return array<array-key, mixed> */
    private function restoreCustomerBackup(ProxmoxConnection $connection, string $node, string $kind, int $vmId, string $volume, string $identityTag): array
    {
        try {
            $archive = ProxmoxBackupConfiguration::archive($this->api->backupConfiguration($connection, $node, $volume));
            if ('' === $identityTag || !in_array($identityTag, explode(';', $archive['tags'] ?? ''), true)) {
                throw new \InvalidArgumentException('pve.error.restore_ownership');
            }
            if (($this->api->status($connection, $node, $vmId, $kind)['status'] ?? '') !== 'stopped') {
                throw new \InvalidArgumentException('pve.error.restore_running');
            }
            $current = $this->api->guestConfig($connection, $node, $vmId, $kind, current: true);
            if (!in_array($identityTag, explode(';', JsonMap::strings($current)['tags'] ?? ''), true)) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
            if (!empty($current['lock'])) {
                throw new \InvalidArgumentException('pve.error.task_active');
            }
            if (!empty($current['protection'])) {
                throw new \InvalidArgumentException('pve.error.restore_protected');
            }
            foreach ($this->api->snapshots($connection, $node, $vmId, $kind) as $snapshot) {
                if ('current' !== ($snapshot['name'] ?? null)) {
                    throw new \InvalidArgumentException('pve.error.restore_snapshots');
                }
            }
            $configuration = ProxmoxBackupConfiguration::comparable($current);
            if (ProxmoxBackupConfiguration::comparable($archive) !== $configuration) {
                throw new \InvalidArgumentException('pve.error.restore_configuration');
            }
            // QEMU keeps the original storage of each backed-up disk when no override is sent.
            $storage = 'lxc' === $kind ? ProxmoxBackupConfiguration::containerStorage($configuration) : null;
            if (null !== $storage) {
                $this->assertStorage($connection, $node, $storage, 'rootdir');
            }
        } catch (ProxmoxApiException $exception) {
            throw new \InvalidArgumentException(in_array($exception->statusCode, [401, 403], true) ? 'pve.error.permissions' : 'pve.error.restore_verification', previous: $exception);
        }

        return $this->api->restoreBackup($connection, $node, $vmId, $volume, $kind, $storage, true);
    }

    private function backupMatches(array $backup, int $vmId, string $kind): bool
    {
        $volume = (string) ($backup['volid'] ?? '');
        $vmMatches = isset($backup['vmid']) ? (int) $backup['vmid'] === $vmId : (bool) preg_match('/(?:vzdump-'.preg_quote($kind, '/').'-'.$vmId.'-|backup\/(?:vm|ct)\/'.$vmId.'\/)/', $volume);
        if (!$vmMatches) {
            return false;
        }
        // PBS distinguishes VM and CT groups even though both share the backup content type.
        if (str_contains($volume, ':backup/ct/')) {
            return 'lxc' === $kind;
        }
        if (str_contains($volume, ':backup/vm/')) {
            return 'qemu' === $kind;
        }

        return !preg_match('/vzdump-(qemu|lxc)-/', $volume, $matches) || $matches[1] === $kind;
    }

    private function guestName(string $name): string
    {
        $name = trim($name);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,62}$/D', $name)) {
            throw new \InvalidArgumentException('pve.error.name');
        }

        return $name;
    }

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (false === $parsed || $parsed < $min || $parsed > $max) {
            throw new \InvalidArgumentException('pve.error.resources');
        }

        return $parsed;
    }
}
