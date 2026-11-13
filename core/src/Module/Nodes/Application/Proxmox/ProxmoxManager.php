<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\User;
use App\Module\Nodes\Application\VmGuestAgentReport;
use App\Module\Nodes\Application\VmProvisioningSettings;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProxmoxManager
{
    public function __construct(private ProxmoxApiClient $api, private SecretsCrypto $crypto, private EntityManagerInterface $em, private AuditLogger $audit, private ProxmoxGuestAgent $guestAgent, private ProxmoxRrdUsage $rrd)
    {
    }

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
    public function guest(ProxmoxCluster $cluster, string $kind, int $vmId): array
    {
        return $this->findGuest($this->overview($cluster)['guests'], $kind, $vmId);
    }

    private function findGuest(array $guests, string $kind, int $vmId): array
    {
        foreach ($guests as $guest) {
            if ((int) ($guest['vmid'] ?? 0) === $vmId && ($guest['type'] ?? '') === $kind) {
                return $guest;
            }
        }
        throw new \InvalidArgumentException('pve.error.guest_not_found');
    }

    public function usage(ProxmoxCluster $cluster, string $kind, int $vmId, ?string $identityTag = null): array
    {
        $overview = $this->overview($cluster);
        $guest = $this->findGuest($overview['guests'], $kind, $vmId);
        if (null !== $identityTag && !in_array($identityTag, explode(';', (string) ($guest['tags'] ?? '')), true)) {
            throw new \InvalidArgumentException('pve.error.identity');
        }
        $node = [];
        foreach ($overview['nodes'] as $row) {
            if (($row['node'] ?? '') === $guest['node']) {
                $node = $row;
                break;
            }
        }

        $connection = $cluster->connection($this->crypto);
        if (null !== $identityTag) {
            $config = $this->api->guestConfig($connection, (string) $guest['node'], $vmId, $kind);
            if (!in_array($identityTag, explode(';', (string) ($config['tags'] ?? '')), true)) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
        }
        $server = $this->api->status($connection, (string) $guest['node'], $vmId, $kind) + $guest;
        $server += $this->rrd->forGuest($connection, (string) $guest['node'], $kind, $vmId, $server);

        return ['server' => $server, 'node' => $node];
    }

    /** Collector already has the cluster inventory; do not refetch it for each VM. */
    public function monitoringUsage(ProxmoxCluster $cluster, array $guest, ?string $identityTag): array
    {
        $connection = $cluster->connection($this->crypto);
        $node = (string) $guest['node'];
        $kind = (string) $guest['type'];
        $vmId = (int) $guest['vmid'];
        if (null !== $identityTag) {
            $config = $this->api->guestConfig($connection, $node, $vmId, $kind);
            if (!in_array($identityTag, explode(';', (string) ($config['tags'] ?? '')), true)) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
        }

        return $guest + $this->rrd->forGuest($connection, $node, $kind, $vmId, $guest);
    }

    public function customerGuests(User $actor): array
    {
        $result = [];
        $clusters = [];
        foreach ($this->em->getRepository(ProxmoxGuestAssignment::class)->findBy(['customer' => $actor]) as $assignment) {
            $id = $assignment->getCluster()->getId();
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
        $this->api->setGuestTags($connection, (string) $guest['node'], $vmId, $kind, implode(';', $tags), $config['digest'] ?? null);

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
            if ($configuration && $customer && !in_array($identityTag, explode(';', (string) ($configuration['tags'] ?? '')), true)) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
            if ($configuration && 'qemu' === $kind) {
                $provisioning = VmProvisioningSettings::proxmoxView($configuration, (string) ($guest['name'] ?? ''));
            }
        }

        $status = $this->api->status($connection, $node, $vmId, $kind);
        if (empty($guest['template'])) {
            $status += $this->rrd->forGuest($connection, $node, $kind, $vmId, $status);
        }

        return [
            'guest' => $guest, 'status' => $status, 'configuration' => $customer ? null : $configuration, 'provisioning' => $provisioning, 'bootDevices' => !$customer && $configuration ? VmProvisioningSettings::bootDevices($configuration) : [],
            'disks' => $configuration ? ProxmoxDisks::attached($configuration, $kind) : [],
            'extraDiskOptions' => !$customer && $configuration ? ['devices' => ProxmoxExtraDisk::slots($configuration, $kind), 'storages' => array_values(array_filter($storages, fn (array $s): bool => $this->supports($s, 'qemu' === $kind ? 'images' : 'rootdir')))] : null,
            'nodeUsage' => array_values(array_filter($overview['nodes'], static fn (array $row): bool => ($row['node'] ?? '') === $node))[0] ?? [],
            'snapshots' => $this->api->snapshots($connection, $node, $vmId, $kind),
            'storages' => $customer ? array_values(array_filter($storages, fn (array $s): bool => ($s['storage'] ?? '') === $backupStorage && $this->supports($s, 'backup'))) : $storages,
            'backups' => $backups,
            'isos' => !$customer && 'qemu' === $kind ? $this->content($connection, $node, $storages, 'iso') : [],
        ];
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

    public function perform(ProxmoxCluster $cluster, string $kind, int $vmId, string $action, array $input, string $requestKey, User $actor, ?string $allowedBackupStorage = null): ProxmoxTask
    {
        $identityTag = null;
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestKey)) {
            throw new \InvalidArgumentException('pve.error.request_key');
        }
        if (!$actor->isAdmin()) {
            if (!in_array($action, ['start', 'shutdown', 'stop', 'reboot', 'snapshot-create', 'snapshot-delete', 'snapshot-rollback', 'backup', 'cloud-init'], true)) {
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
            if ($existing->getAction() !== $action || $existing->getKind() !== $kind || $existing->getVmId() !== $vmId || $existing->getActorId() !== $actor->getId()) {
                throw new \InvalidArgumentException('pve.error.request_key');
            }

            return $existing;
        }
        $connection = $cluster->connection($this->crypto);
        $guest = 'create-vm' === $action ? ['node' => (string) ($input['node'] ?? ''), 'template' => 0] : $this->guest($cluster, $kind, $vmId);
        $node = (string) $guest['node'];
        $this->assertOnlineNode($connection, $node);
        $customer = !$actor->isAdmin();
        if ($customer && !in_array($action, ['start', 'shutdown', 'stop', 'reboot', 'snapshot-create', 'snapshot-delete', 'snapshot-rollback', 'backup', 'cloud-init'], true)) {
            throw new \InvalidArgumentException('pve.error.forbidden');
        }
        if (!empty($guest['template']) && 'clone' !== $action) {
            throw new \InvalidArgumentException('pve.error.template_action');
        }
        if (in_array($action, ['stop', 'reboot', 'snapshot-delete', 'snapshot-rollback', 'backup-delete', 'backup-restore', 'iso-mount'], true) && ($input['confirm'] ?? '') !== '1') {
            throw new \InvalidArgumentException('pve.error.confirm');
        }
        $active = $this->em->getRepository(ProxmoxTask::class)->findOneBy(['cluster' => $cluster, 'vmId' => $vmId, 'state' => ['submitting', 'running']]);
        if ($active instanceof ProxmoxTask) {
            $this->refreshTask($active);
            if ($active->isActive()) {
                throw new \InvalidArgumentException('pve.error.task_active');
            }
        }
        $operation = $this->prepare($connection, $node, $kind, $vmId, $action, $input, $guest, $customer ? $allowedBackupStorage : null, $customer, $identityTag);

        // Claim the operation before contacting PVE. The unique request key prevents duplicate mutations.
        [$task, $claimed] = $this->claim($cluster, $node, $kind, $vmId, $action, $requestKey, $actor);
        if (!$claimed) {
            return $task;
        }
        try {
            $result = $operation();
            $upid = $result['task'] ?? null;
            if (null === $upid && !in_array($action, ['iso-mount', 'backup-delete', 'resources-update', 'cloud-init', 'boot-order', 'guest-agent', 'disk-grow'], true) && !('disk-add' === $action && 'lxc' === $kind)) {
                throw new \RuntimeException('Missing upstream task ID.');
            }
            $task->dispatched(is_string($upid) ? $upid : null);
            $this->audit->log($actor, 'proxmox.action_dispatched', ['cluster_id' => $cluster->getId(), 'node' => $node, 'vm_id' => $vmId, 'action' => $action, 'task_id' => $task->getId()]);
        } catch (\Throwable $error) {
            if ($error instanceof \App\Module\Nodes\Domain\Proxmox\ProxmoxApiException && $error->statusCode >= 400 && $error->statusCode < 500) {
                $task->finish(false, in_array($error->statusCode, [401, 403], true) ? 'permission_denied' : 'dispatch_failed');
            } else {
                // A lost response or server error does not prove that a write was rejected.
                $task->uncertain();
            }
            $this->audit->log($actor, 'proxmox.action_failed', ['cluster_id' => $cluster->getId(), 'vm_id' => $vmId, 'action' => $action]);
        }
        $this->em->flush();

        return $task;
    }

    public function refreshTask(ProxmoxTask $task): void
    {
        if (!$task->isActive() || 'uncertain' === $task->getState()) {
            return;
        }
        if (null === $task->getUpid()) {
            if ($task->getCreatedAt() < new \DateTimeImmutable('-2 minutes')) {
                $task->uncertain();
                $this->em->flush();
            }

            return;
        }
        $status = $this->api->taskStatus($task->getCluster()->connection($this->crypto), $task->getNode(), $task->getUpid());
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
        if (!$actor->isAdmin() || 'uncertain' !== $task->getState() || mb_strlen(trim($note)) < 10 || mb_strlen($note) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note)) {
            throw new \InvalidArgumentException('pve.error.review');
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
            $path = '/nodes/'.$name;
            $permissions = $this->api->permissions($connection, $path);
            if (empty($permissions[$path]['Sys.Audit'])) {
                throw new \InvalidArgumentException('pve.error.review_permissions');
            }
            if ([] !== $this->api->activeTasks($connection, $name, $task->getVmId() ?: null)) {
                throw new \InvalidArgumentException('pve.error.review_active');
            }
        }
        if (!$checkedSource) {
            throw new \InvalidArgumentException('pve.error.review_nodes');
        }
        foreach ($overview['guests'] as $guest) {
            if (($guest['type'] ?? '') === $task->getKind() && (int) ($guest['vmid'] ?? 0) === $task->getVmId()) {
                $config = $this->api->guestConfig($connection, (string) $guest['node'], $task->getVmId(), $task->getKind());
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

    /** @return array{ProxmoxTask, bool} */
    private function claim(ProxmoxCluster $cluster, string $node, string $kind, int $vmId, string $action, string $requestKey, User $actor): array
    {
        $connection = $this->em->getConnection();
        try {
            // DBAL keeps the entity manager usable if a concurrent unique claim wins.
            $connection->insert('proxmox_tasks', ['cluster_id' => $cluster->getId(), 'node' => $node, 'kind' => $kind, 'vm_id' => $vmId, 'action' => $action, 'request_key' => $requestKey, 'state' => 'submitting', 'guest_lock' => $kind.':'.$vmId, 'actor_id' => $actor->getId(), 'created_at' => new \DateTimeImmutable()], ['created_at' => \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $existing = $this->em->getRepository(ProxmoxTask::class)->findOneBy(['cluster' => $cluster, 'requestKey' => $requestKey]);
            if (!$existing instanceof ProxmoxTask) {
                throw new \InvalidArgumentException('pve.error.task_active');
            }
            if ($existing->getAction() !== $action || $existing->getKind() !== $kind || $existing->getVmId() !== $vmId || $existing->getActorId() !== $actor->getId()) {
                throw new \InvalidArgumentException('pve.error.request_key');
            }

            return [$existing, false];
        }
        $task = $this->em->find(ProxmoxTask::class, (int) $connection->lastInsertId());
        if (!$task instanceof ProxmoxTask) {
            throw new \RuntimeException('Unable to read claimed Proxmox operation.');
        }

        return [$task, true];
    }

    private function prepare(ProxmoxConnection $connection, string $node, string $kind, int $vmId, string $action, array $input, array $guest, ?string $allowedStorage, bool $customer, ?string $identityTag = null): \Closure
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
            $disk = is_string($input['disk'] ?? null) ? $input['disk'] : '';
            $size = $this->boundedInt($input['size_gib'] ?? '', 1, 65536);
            $expected = $this->boundedInt($input['expected_bytes'] ?? '', 1, 70368744177664);
            $attached = array_column(ProxmoxDisks::attached($config, $kind), null, 'device');
            if (!isset($attached[$disk]) || !is_string($config['digest'] ?? null) || '' === $config['digest'] || !empty($config['lock'])) {
                throw new \InvalidArgumentException('pve.error.disk_unavailable');
            }
            if ($attached[$disk]['size_bytes'] !== $expected || ($input['expected_digest'] ?? null) !== $config['digest']) {
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

            return fn (): array => $this->api->growDisk($connection, $node, $vmId, $kind, $disk, $size, $config['digest']);
        }
        if (in_array($action, ['cloud-init', 'boot-order', 'guest-agent'], true)) {
            if ('qemu' !== $kind || ($customer && 'cloud-init' !== $action)) {
                throw new \InvalidArgumentException('pve.error.forbidden');
            }
            if (($this->api->status($connection, $node, $vmId, $kind)['status'] ?? '') !== 'stopped') {
                throw new \InvalidArgumentException('pve.error.resources_running');
            }
            $config = $this->api->guestConfig($connection, $node, $vmId, $kind);
            $digest = is_string($config['digest'] ?? null) ? $config['digest'] : null;
            if ($customer && (!$identityTag || !in_array($identityTag, explode(';', (string) ($config['tags'] ?? '')), true))) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
            if ('cloud-init' === $action) {
                if (!VmProvisioningSettings::hasCloudInit($config) || !empty($config['cicustom'])) {
                    throw new \InvalidArgumentException('vm_config.no_cloud_init');
                }
                $hostname = VmProvisioningSettings::hostname($input['hostname'] ?? '');
                $username = is_string($input['username'] ?? null) ? trim($input['username']) : '';
                if (!preg_match('/^[a-z_][a-z0-9_-]{0,30}$/D', $username)) {
                    throw new \InvalidArgumentException('vm_config.invalid_user');
                }
                $keys = VmProvisioningSettings::publicKeys($input['ssh_keys'] ?? '');

                return fn (): array => $this->api->updateProvisioning($connection, $node, $vmId, $hostname, $username, $keys, $digest);
            }
            if ('boot-order' === $action) {
                $raw = is_string($input['boot_order'] ?? null) ? trim($input['boot_order']) : '';
                $devices = explode(';', $raw);
                if (count($devices) < 1 || count($devices) > 8 || count(array_unique($devices)) !== count($devices) || array_diff($devices, VmProvisioningSettings::bootDevices($config))) {
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

            return fn (): array => $this->api->updateResources($connection, $node, $vmId, $kind, $cores, $memory, is_string($config['digest'] ?? null) ? $config['digest'] : null);
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

            return fn (): array => $this->api->mountIso($connection, $node, $vmId, $volume, $slot, $config['digest'] ?? null);
        }
        if ('clone' === $action) {
            if (empty($guest['template'])) {
                throw new \InvalidArgumentException('pve.error.template_action');
            }
            $name = $this->guestName((string) ($input['name'] ?? ''));
            $newId = $this->api->nextVmId($connection);

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
            $id = $this->api->nextVmId($connection);
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
