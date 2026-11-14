<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Kvm;

use App\Infrastructure\Config\JsonArray;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Nodes\Application\VmGuestAgentReport;
use App\Module\Nodes\Domain\Entity\KvmGuestAssignment;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CustomerKvmService
{
    public const ACTIONS = ['vm.start', 'vm.shutdown', 'vm.stop', 'vm.reboot', 'vm.cloud_init', 'vm.boot_order', 'vm.agent_status', 'snapshot.create', 'snapshot.revert', 'snapshot.delete', 'backup.create', 'backup.restore', 'backup.delete'];

    /**
     * Symfony constructs this autowired service.
     *
     * @psalm-api
     */
    public function __construct(private KvmPanelService $kvm, private EntityManagerInterface $em, private AuditLogger $audit, private KvmOperationLock $lock)
    {
    }

    public function guest(KvmGuestAssignment $assignment, User $actor): ?array
    {
        $this->assertOwner($assignment, $actor);
        foreach ($this->kvm->inventory($assignment->getNode())['guests'] ?? [] as $row) {
            if (($row['id'] ?? '') !== $assignment->getVmUuid() || true !== ($row['managed'] ?? false) || !empty($row['error'])) {
                continue;
            }
            $safe = array_intersect_key($row, array_flip(['id', 'label', 'state', 'vcpus', 'memory_mib', 'disk_gib', 'console_enabled']));
            $safe['network_boot_first'] = true === ($row['network_boot_first'] ?? false);
            $safe['guest_agent_enabled'] = true === ($row['guest_agent_enabled'] ?? false);
            $safe['cloud_init'] = null;
            if (is_array($row['cloud_init'] ?? null)) {
                $ci = $row['cloud_init'];
                $safe['cloud_init'] = ['hostname' => is_string($ci['hostname'] ?? null) ? $ci['hostname'] : '', 'username' => is_string($ci['username'] ?? null) ? $ci['username'] : '', 'ssh_keys' => array_values(array_filter(JsonArray::orEmpty($ci['ssh_keys'] ?? null), 'is_string'))];
            }
            $safe['snapshots'] = array_values(array_filter(JsonArray::orEmpty($row['snapshots'] ?? null), static fn ($name): bool => is_string($name) && 1 === preg_match('/^[a-z][a-z0-9-]{0,47}$/D', $name)));
            $safe['backups'] = [];
            foreach ($row['backups'] ?? [] as $backup) {
                if (!is_array($backup) || !is_string($backup['id'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $backup['id'])) {
                    continue;
                }
                $createdAt = null;
                if (is_string($backup['created_at'] ?? null) && '' !== trim($backup['created_at'])) {
                    try {
                        $createdAt = (new \DateTimeImmutable($backup['created_at']))->format(DATE_ATOM);
                    } catch (\Exception) {
                    }
                }
                $safe['backups'][] = ['id' => $backup['id'], 'created_at' => $createdAt, 'size' => is_numeric($backup['size'] ?? null) && is_finite((float) $backup['size']) && $backup['size'] >= 0 ? (int) $backup['size'] : null];
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
        if (!$guest) {
            throw new \InvalidArgumentException('kvm.customer_unavailable');
        }
        if (in_array($action, ['vm.stop', 'vm.reboot', 'snapshot.revert', 'snapshot.delete', 'backup.restore', 'backup.delete'], true) && ($input['confirm'] ?? '') !== '1') {
            throw new \InvalidArgumentException('kvm.customer_confirm');
        }
        if ((str_starts_with($action, 'snapshot.') || str_starts_with($action, 'backup.') || in_array($action, ['vm.cloud_init', 'vm.boot_order'], true)) && ($guest['state'] ?? '') !== 'shut off') {
            throw new \InvalidArgumentException('kvm.customer_stopped');
        }
        $payload = ['operation_id' => $input['operation_id'] ?? '', 'vm_id' => $assignment->getVmUuid()];
        if ('vm.cloud_init' === $action) {
            if (!$guest['cloud_init']) {
                throw new \InvalidArgumentException('kvm.customer_unavailable');
            }
            $payload['hostname'] = $input['hostname'] ?? '';
            $payload['ssh_keys'] = $input['ssh_keys'] ?? '';
        } elseif ('vm.boot_order' === $action) {
            $payload['boot_order'] = $input['boot_order'] ?? '';
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
            if ('backup.restore' === $action && [] !== $guest['snapshots']) {
                throw new \InvalidArgumentException('kvm.customer_restore_snapshots');
            }
        }

        return $this->lock->run($assignment->getNode(), function () use ($assignment, $actor, $action, $payload): AgentJob {
            $owner = $this->em->getConnection()->fetchOne('SELECT customer_id FROM kvm_guest_assignments WHERE id = ?', [$assignment->getId()]);
            if ((string) $owner !== (string) $actor->getId()) {
                throw new \InvalidArgumentException('kvm.customer_forbidden');
            }
            $job = $this->kvm->submit($assignment->getNode(), $action, $payload);
            $this->audit->log($actor, 'kvm.customer_job_requested', ['assignment_id' => $assignment->getId(), 'job_id' => $job->getId(), 'action' => $action]);
            $this->em->flush();

            return $job;
        });
    }

    public function recent(KvmGuestAssignment $assignment, User $actor): array
    {
        $this->assertOwner($assignment, $actor);
        $rows = $this->em->getRepository(AgentJob::class)->createQueryBuilder('j')
            ->select('j.id', 'j.payload', 'j.status', 'j.createdAt')
            ->where('j.node = :node')->andWhere('j.type = :type')
            ->setParameter('node', $assignment->getNode())->setParameter('type', 'kvm.managed')
            ->orderBy('j.createdAt', 'DESC')->setMaxResults(100)->getQuery()->getArrayResult();
        $result = [];
        foreach ($rows as $job) {
            if (($job['payload']['vm_id'] ?? '') === $assignment->getVmUuid() && in_array($job['payload']['action'] ?? '', self::ACTIONS, true)) {
                $result[] = ['id' => $job['id'], 'action' => $job['payload']['action'], 'status' => $job['status'] instanceof AgentJobStatus ? $job['status']->value : (string) $job['status'], 'created_at' => $job['createdAt']->format(DATE_ATOM)];
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

    private function assertOwner(KvmGuestAssignment $assignment, User $actor): void
    {
        if (!$actor->isCustomer() || $assignment->getCustomer()->getId() !== $actor->getId()) {
            throw new \InvalidArgumentException('kvm.customer_forbidden');
        }
    }
}
