<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Infrastructure\Config\JsonMap;
use App\Infrastructure\Doctrine\EntityIdentifier;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Nodes\Domain\Entity\ProxmoxGuestAssignment;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Nodes\Domain\Proxmox\ProxmoxApiException;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class ProxmoxRentalLifecycle
{
    public function __construct(private EntityManagerInterface $em, private ProxmoxApiClient $api, private SecretsCryptoInterface $crypto, private ProxmoxIpPools $pools, private LockFactory $locks)
    {
    }

    public function queue(ProxmoxTask $installation, string $action): ProxmoxTask
    {
        $origin = EntityIdentifier::require($installation->getId());
        $cluster = $installation->getCluster();
        $vmId = $installation->getTargetVmId();
        if ($installation->isActive() || null === $vmId || !isset($installation->getInstallation()['rental_id']) || !in_array($action, ['rental-stop', 'rental-delete'], true)) {
            throw new \DomainException('PROXMOX_INSTALLATION_PENDING_REVIEW');
        }
        $tasks = $this->em->getRepository(ProxmoxTask::class);
        $previous = $tasks->findOneBy(['originTaskId' => $origin, 'action' => $action], ['id' => 'DESC']);
        if (null !== $previous && 'failed' !== $previous->getState()) {
            return $previous;
        }
        $key = substr(hash('sha256', $origin.':'.$action.':'.($previous?->getId() ?? 0)), 0, 32);
        try {
            $db = $this->em->getConnection();
            $db->insert('proxmox_tasks', ['cluster_id' => $cluster->getId(), 'node' => $installation->getNode(), 'kind' => 'qemu', 'vm_id' => $vmId,
                'action' => $action, 'request_key' => $key, 'state' => 'queued', 'guest_lock' => 'qemu:'.$vmId, 'origin_task_id' => $origin,
                'created_at' => new \DateTimeImmutable()], ['created_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('PROXMOX_GUEST_BUSY');
        }

        return $this->em->find(ProxmoxTask::class, (int) $db->lastInsertId()) ?? throw new \LogicException('Missing queued Proxmox operation.');
    }

    public function revokeAccess(ProxmoxTask $installation): void
    {
        $assignment = $this->em->getRepository(ProxmoxGuestAssignment::class)->findOneBy(['cluster' => $installation->getCluster(), 'vmId' => $installation->getTargetVmId(), 'kind' => 'qemu']);
        if (null !== $assignment && $assignment->getIdentityTag() === 'easywi-owned-'.substr($installation->getRequestKey(), 0, 16)) {
            $this->em->remove($assignment);
        }
    }

    public function discardUnsubmitted(ProxmoxTask $installation): bool
    {
        $this->em->refresh($installation, LockMode::PESSIMISTIC_WRITE);
        if ('queued' === $installation->getState()) {
            $installation->finish(false, 'preflight_cancelled');
        }
        if (!in_array($installation->getResult(), ['preflight_cancelled', 'preflight_failed'], true)) {
            return false;
        }
        foreach ($this->pools->leases($installation) as $lease) {
            $this->em->remove($lease);
        }

        return true;
    }

    public function advance(ProxmoxTask $task): void
    {
        $originId = $task->getOriginTaskId();
        if (null === $originId || $this->em->getConnection()->isTransactionActive()) {
            return;
        }
        $lock = $this->locks->createLock('proxmox.installation.'.$originId, 90);
        if (!$lock->acquire()) {
            return;
        }
        try {
            $this->em->refresh($task);
            $installation = $this->em->find(ProxmoxTask::class, $originId);
            $vmId = $task->getVmId();
            if (!$task->isActive() || 'uncertain' === $task->getState()) {
                return;
            }
            if (null === $installation || $installation->isActive() || $installation->getTargetVmId() !== $vmId || $installation->getCluster() !== $task->getCluster()
                || !isset($installation->getInstallation()['rental_id'])) {
                $task->uncertain('identity_mismatch');
                $this->em->flush();

                return;
            }
            $connection = $task->getCluster()->connection($this->crypto);
            $delete = 'rental-delete' === $task->getAction();
            $upid = $task->getUpid();
            if (null !== $upid) {
                $state = $this->api->taskStatus($connection, $task->getNode(), $upid);
                if ('stopped' !== ($state['status'] ?? null)) {
                    return;
                }
                if ('OK' !== ($state['exitstatus'] ?? null)) {
                    $task->finish(false, 'upstream_failed');
                    $this->em->flush();

                    return;
                }
            }
            $node = $this->ownedNode($installation, $connection, $vmId);
            if (null === $node && $this->api->isGuestIdFree($connection, $vmId)) {
                if ($delete) {
                    foreach ($this->pools->leases($installation) as $lease) {
                        $this->em->remove($lease);
                    }
                }
                $task->finish(true, 'OK');
                $this->em->flush();

                return;
            }
            if (null === $node) {
                return;
            }
            $stopped = ($this->api->status($connection, $node, $vmId)['status'] ?? null) === 'stopped';
            if (!$delete && $stopped) {
                $task->finish(true, 'OK');
                $this->em->flush();

                return;
            }
            if ('queued' !== $task->getState() || ($delete && !$stopped)) {
                if ($task->getCreatedAt() < new \DateTimeImmutable('-5 minutes')) {
                    $task->uncertain('outcome_unconfirmed');
                    $this->em->flush();
                }

                return;
            }
            if (!$this->api->hasPrivilege($connection, '/nodes/'.$node, 'Sys.Audit')) {
                $task->uncertain('permission_denied');
                $this->em->flush();

                return;
            }
            if ([] !== $this->api->activeTasks($connection, $node, $vmId)) {
                return;
            }
            // Commit the submitting state before a remote mutation. A lost reply is reconciled by observation.
            $task->submittedOn($node);
            $this->em->flush();
            try {
                $upid = $delete ? $this->api->deleteStoppedQemuVm($connection, $node, $vmId) : (JsonMap::strings($this->api->changePowerState($connection, $node, $vmId, 'stop'))['task'] ?? '');
                if ('' !== $upid) {
                    $task->dispatched($upid);
                    $this->em->flush();
                }
            } catch (ProxmoxApiException $error) {
                if ($error->statusCode >= 400 && $error->statusCode < 500) {
                    $task->finish(false, 'dispatch_failed');
                    $this->em->flush();
                }
            }
        } catch (\InvalidArgumentException) {
            $task->uncertain('identity_mismatch');
            $this->em->flush();
        } finally {
            $lock->release();
        }
    }

    private function ownedNode(ProxmoxTask $installation, ProxmoxConnection $connection, int $vmId): ?string
    {
        foreach ($this->api->clusterResources($connection, 'vm') as $guest) {
            if ((int) ($guest['vmid'] ?? 0) !== $vmId) {
                continue;
            }
            $node = JsonMap::strings($guest)['node'] ?? '';
            if ('qemu' !== ($guest['type'] ?? null) || '' === $node || !empty($guest['template'])) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
            $config = $this->api->guestConfig($connection, $node, $vmId);
            if (($config['description'] ?? '') !== ProxmoxInstallation::marker($installation->getRequestKey())) {
                throw new \InvalidArgumentException('pve.error.identity');
            }
            if (!empty($config['lock'])) {
                throw new ProxmoxApiException('VM lock is still active.');
            }

            return $node;
        }

        return null;
    }
}
