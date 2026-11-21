<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Kvm;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\JsonMap;
use App\Repository\EntityQueryResult;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Domain\Entity\ShopProduct;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Nodes\Application\VmGuestAgentReport;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @psalm-type CustomerGuest = array{
 *     network_boot_first: bool, guest_agent_enabled: bool,
 *     cloud_init: array{hostname: string, username: string, ssh_keys: list<string>}|null,
 *     static_addresses: list<string>,
 *     reinstall_templates: list<string>,
 *     snapshots: list<string>, has_snapshots: bool, backups: list<array{id: string, created_at: string|null, size: int|null}>,
 *     ...<array-key, mixed>
 * }
 */
final readonly class CustomerKvmService
{
    public const ACTIONS = ['vm.start', 'vm.shutdown', 'vm.stop', 'vm.reboot', 'vm.cloud_init', 'vm.boot_order', 'vm.agent_status', 'vm.root_password', 'vm.address_switch', 'vm.reinstall', 'snapshot.create', 'snapshot.revert', 'snapshot.delete', 'backup.create', 'backup.restore', 'backup.delete'];

    /**
     * Symfony constructs this autowired service.
     *
     * @psalm-api
     */
    public function __construct(private KvmPanelService $kvm, private EntityManagerInterface $em, private AuditLoggerInterface $audit, private KvmOperationLock $lock)
    {
    }

    /** @return CustomerGuest|null */
    public function guest(KvmGuestAssignment $assignment, User $actor): ?array
    {
        $this->assertOwner($assignment, $actor);
        $inventory = $this->kvm->inventory($assignment->getNode());
        foreach ($inventory['guests'] ?? [] as $row) {
            if (($row['id'] ?? '') !== $assignment->getVmUuid() || true !== ($row['managed'] ?? false) || !empty($row['error'])) {
                continue;
            }
            $safe = array_intersect_key($row, array_flip(['id', 'label', 'state', 'vcpus', 'memory_mib', 'disk_gib', 'console_enabled']));
            $safe['network_boot_first'] = true === ($row['network_boot_first'] ?? false);
            $safe['guest_agent_enabled'] = true === ($row['guest_agent_enabled'] ?? false);
            $staticAddresses = [];
            $addresses = JsonArray::orEmpty($row['static_addresses'] ?? null);
            foreach (array_slice(array_keys($addresses), 0, 2) as $key) {
                $fields = JsonMap::strings(JsonArray::orEmpty($addresses[$key]));
                [$address] = explode('/', $fields['address'] ?? '', 2);
                if (false !== filter_var($address, FILTER_VALIDATE_IP)) {
                    $staticAddresses[] = $address;
                }
            }
            $safe['static_addresses'] = $staticAddresses;
            $safe['cloud_init'] = null;
            if (is_array($row['cloud_init'] ?? null)) {
                $ci = JsonArray::orEmpty($row['cloud_init']);
                $fields = JsonMap::strings($ci);
                $safe['cloud_init'] = ['hostname' => $fields['hostname'] ?? '', 'username' => $fields['username'] ?? '', 'ssh_keys' => array_values(array_filter(JsonArray::orEmpty($ci['ssh_keys'] ?? null), 'is_string'))];
            }
            $snapshots = JsonArray::orEmpty($row['snapshots'] ?? null);
            $safe['has_snapshots'] = [] !== $snapshots;
            $safe['snapshots'] = array_values(array_filter($snapshots, static fn ($name): bool => is_string($name) && 1 === preg_match('/^[a-z][a-z0-9-]{0,47}$/D', $name)));
            $reinstallTemplates = [];
            if (($row['state'] ?? null) === 'shut off' && !$safe['network_boot_first'] && [] === $safe['snapshots'] && null !== $safe['cloud_init']
                && 1 === ($assignment->getNode()->getMetadata()['kvm_managed']['reinstall_version'] ?? null)) {
                $approved = [(string) ($row['template'] ?? '')];
                foreach ($this->em->getRepository(ShopProduct::class)->findBy(['kind' => ShopProductKind::Kvm, 'node' => $assignment->getNode(), 'isActive' => true, 'isPublicActive' => true]) as $product) {
                    $approved[] = (string) ($product->getProvisioningOptions()['kvm_template'] ?? '');
                }
                $diskBytes = (int) ($row['disk_gib'] ?? 0) * 1073741824;
                foreach ($inventory['templates'] ?? [] as $template) {
                    $fields = JsonMap::strings($template);
                    $reference = ($fields['id'] ?? '').'--'.($fields['version'] ?? '');
                    $size = self::backupSize($template['virtual_size'] ?? null);
                    if (null !== $size && $size > 0 && $size <= $diskBytes && in_array($reference, $approved, true)) {
                        $reinstallTemplates[] = $reference;
                    }
                }
            }
            $safe['reinstall_templates'] = $reinstallTemplates;
            $safe['backups'] = [];
            $backups = JsonArray::orEmpty($row['backups'] ?? null);
            foreach (array_keys($backups) as $index) {
                $raw = JsonArray::orEmpty($backups[$index]);
                $backup = JsonMap::strings($raw);
                if (!isset($backup['id']) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $backup['id'])) {
                    continue;
                }
                $createdAt = null;
                if (isset($backup['created_at']) && '' !== trim($backup['created_at'])) {
                    try {
                        $createdAt = (new \DateTimeImmutable($backup['created_at']))->format(DATE_ATOM);
                    } catch (\Exception) {
                    }
                }
                $safe['backups'][] = ['id' => $backup['id'], 'created_at' => $createdAt, 'size' => self::backupSize($raw['size'] ?? null)];
            }

            return $safe;
        }

        return null;
    }

    public function submit(KvmGuestAssignment $assignment, User $actor, string $action, array $input): AgentJob
    {
        $this->assertOwner($assignment, $actor);
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('kvm.customer_forbidden');
        }
        $guest = $this->guest($assignment, $actor);
        if ($guest === null) {
            throw new \InvalidArgumentException('kvm.customer_unavailable');
        }
        if (in_array($action, ['vm.stop', 'vm.reboot', 'snapshot.revert', 'snapshot.delete', 'backup.restore', 'backup.delete'], true) && ($input['confirm'] ?? '') !== '1') {
            throw new \InvalidArgumentException('kvm.customer_confirm');
        }
        if ((str_starts_with($action, 'snapshot.') || str_starts_with($action, 'backup.') || in_array($action, ['vm.cloud_init', 'vm.boot_order'], true)) && ($guest['state'] ?? '') !== 'shut off') {
            throw new \InvalidArgumentException('kvm.customer_stopped');
        }
        $input = JsonMap::strings($input);
        $payload = ['operation_id' => $input['operation_id'] ?? '', 'vm_id' => $assignment->getVmUuid()];
        if ('vm.cloud_init' === $action) {
            if ($guest['cloud_init'] === null) {
                throw new \InvalidArgumentException('kvm.customer_unavailable');
            }
            $payload['hostname'] = $input['hostname'] ?? '';
            $payload['ssh_keys'] = $input['ssh_keys'] ?? '';
        } elseif ('vm.boot_order' === $action) {
            $payload['boot_order'] = $input['boot_order'] ?? '';
        } elseif ('vm.root_password' === $action) {
            if (($guest['state'] ?? '') !== 'running' || !$guest['guest_agent_enabled'] || ($input['confirm'] ?? '') !== '1') {
                throw new \InvalidArgumentException('kvm.customer_unavailable');
            }
            $payload['password_hash'] = $input['password_hash'] ?? '';
            $payload['confirm'] = '1';
        } elseif ('vm.reinstall' === $action) {
            $template = $input['template'] ?? '';
            if (!in_array($template, $guest['reinstall_templates'], true) || ($input['confirm'] ?? '') !== '1') {
                throw new \InvalidArgumentException('kvm.customer_reinstall_unavailable');
            }
            $payload['template'] = $template;
            $payload['confirm'] = '1';
        } elseif ('vm.address_switch' === $action) {
            $address = $input['expected_address'] ?? '';
            if (($guest['state'] ?? null) !== 'shut off' || null === $guest['cloud_init'] || $guest['network_boot_first'] || !in_array($address, $guest['static_addresses'], true) || ($input['confirm'] ?? '') !== '1') {
                throw new \InvalidArgumentException('vm_config.address_switch_unavailable');
            }
            $payload['expected_address'] = $address;
            $payload['confirm'] = '1';
        }
        if (str_starts_with($action, 'snapshot.')) {
            $payload['snapshot'] = $input['snapshot'] ?? '';
            if ('snapshot.create' !== $action && !in_array($payload['snapshot'], $guest['snapshots'], true)) {
                throw new \InvalidArgumentException('kvm.customer_snapshot');
            }
        }
        if (in_array($action, ['backup.restore', 'backup.delete'], true)) {
            $payload['backup_id'] = $input['backup_id'] ?? '';
            if (!in_array($payload['backup_id'], array_column($guest['backups'], 'id'), true)) {
                throw new \InvalidArgumentException('kvm.customer_backup');
            }
            if ('backup.restore' === $action && $guest['has_snapshots']) {
                throw new \InvalidArgumentException('kvm.customer_restore_snapshots');
            }
        }

        return $this->lock->run($assignment->getNode(), function () use ($assignment, $actor, $action, $payload): AgentJob {
            $owner = filter_var($this->em->getConnection()->fetchOne('SELECT customer_id FROM kvm_guest_assignments WHERE id = ?', [$assignment->getId()]), FILTER_VALIDATE_INT);
            if ($owner === false || (string) $owner !== (string) $actor->getId()) {
                throw new \InvalidArgumentException('kvm.customer_forbidden');
            }
            $job = $this->kvm->submit($assignment->getNode(), $action, $payload);
            $this->audit->log($actor, 'kvm.customer_job_requested', ['assignment_id' => $assignment->getId(), 'job_id' => $job->getId(), 'action' => $action]);
            $this->em->flush();

            return $job;
        });
    }

    /** @return list<array{id: string, action: string, status: string, created_at: string, restore_error: ?string}> */
    public function recent(KvmGuestAssignment $assignment, User $actor): array
    {
        $this->assertOwner($assignment, $actor);
        $rows = EntityQueryResult::all($this->em->getRepository(AgentJob::class)->createQueryBuilder('j')
            ->where('j.node = :node')->andWhere('j.type = :type')
            ->setParameter('node', $assignment->getNode())->setParameter('type', 'kvm.managed')
            ->orderBy('j.createdAt', 'DESC')->setMaxResults(100)->getQuery(), AgentJob::class);
        $result = [];
        foreach ($rows as $job) {
            $payload = JsonMap::strings($job->getPayload());
            if (($payload['vm_id'] ?? '') === $assignment->getVmUuid() && isset($payload['action']) && in_array($payload['action'], self::ACTIONS, true)) {
                $restoreError = 'backup.restore' === $payload['action'] && AgentJobStatus::Failed === $job->getStatus()
                    ? match ($job->getErrorText()) {
                        'VM must be shut off for this operation' => 'kvm.customer_stopped',
                        'delete VM snapshots before restoring a backup' => 'kvm.customer_restore_snapshots',
                        'backup not found or invalid manifest' => 'kvm.customer_backup',
                        'backup checksum mismatch; original VM disk retained' => 'kvm.customer_backup_checksum',
                        default => 'kvm.customer_restore_failed',
                    }
                    : null;
                $result[] = ['id' => $job->getId(), 'action' => $payload['action'], 'status' => $job->getStatus()->value, 'created_at' => $job->getCreatedAt()->format(DATE_ATOM), 'restore_error' => $restoreError];
                if (20 === count($result)) {
                    break;
                }
            }
        }

        return $result;
    }

    public function jobView(AgentJob $job): array
    {
        $view = ['id' => $job->getId(), 'action' => $job->getPayload()['action'] ?? '', 'status' => $job->getStatus()->value, 'created_at' => $job->getCreatedAt()->format(DATE_ATOM)];
        if ('vm.agent_status' === $view['action'] && AgentJobStatus::Success === $job->getStatus()) {
            $view['guest_agent'] = VmGuestAgentReport::sanitize($job->getResultPayload()['guest_agent'] ?? null);
        }

        return $view;
    }

    private static function backupSize(mixed $value): ?int
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0.0 ? (int) $value : null;
    }

    private function assertOwner(KvmGuestAssignment $assignment, User $actor): void
    {
        if (!$actor->isCustomer() || $assignment->getCustomer()->getId() !== $actor->getId()) {
            throw new \InvalidArgumentException('kvm.customer_forbidden');
        }
    }
}
