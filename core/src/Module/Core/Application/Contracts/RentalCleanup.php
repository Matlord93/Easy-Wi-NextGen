<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Backup\BackupArchiveInventory;
use App\Module\Core\Application\Backup\BackupLeaseStoreInterface;
use App\Module\Core\Application\Backup\BackupPlan;
use App\Module\Core\Application\Backup\BackupRetentionRunner;
use App\Module\Core\Application\Backup\RetentionPolicy;
use App\Module\Core\Application\Backup\WritableBackupPlanStoreInterface;
use App\Module\Core\Application\SecretsCryptoInterface;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\ShopProductKind;
use App\Module\Nodes\Application\Proxmox\ProxmoxRentalLifecycle;
use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use App\Module\Ports\Domain\Entity\PortBlock;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class RentalCleanup
{
    public function __construct(private EntityManagerInterface $em, private RentalSettings $settings, private RentalCapacity $capacity, private RentalNotices $notices, private AgentJobDispatcher $dispatcher, private SecretsCryptoInterface $crypto, private AuditLoggerInterface $audit, private BackupLeaseStoreInterface $plans, private WritableBackupPlanStoreInterface $writablePlans, private BackupRetentionRunner $retention, private BackupArchiveInventory $inventory, private RentalBackupCleanup $legacyBackups, private ProxmoxContractProvider $proxmox, private ProxmoxRentalLifecycle $proxmoxLifecycle)
    {
    }

    public function run(\DateTimeImmutable $now): void
    {
        $settings = $this->settings->get();
        if (true !== $settings['cleanup_enabled']) {
            return;
        }
        /** @var list<ShopContract> $contracts */
        $contracts = $this->em->getRepository(ShopContract::class)->createQueryBuilder('c')->where("c.status = 'ended' AND c.retentionHold = false AND (c.purgedAt IS NULL OR c.backupsPurgedAt IS NULL)")->andWhere('c.cleanupCheckedAt IS NULL OR c.cleanupCheckedAt < :minute')->setParameter('minute', $now->modify('-1 minute'), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)->orderBy('c.cleanupCheckedAt')->addOrderBy('c.id')->setMaxResults(10)->getQuery()->getResult();
        $started = microtime(true);
        foreach ($contracts as $contract) {
            if (microtime(true) - $started > 20) {
                break;
            }
            $this->em->wrapInTransaction(function () use ($contract, $now, $settings): void {
                $this->em->refresh($contract->getAccount(), LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($contract, LockMode::PESSIMISTIC_WRITE);
                $endedAt = $contract->getEndedAt();
                if ($contract->isRetentionHold() || 'ended' !== $contract->getStatus() || null === $endedAt) {
                    return;
                }
                $contract->cleanupChecked($now);
                if (ShopProductKind::Domain === $contract->getKind()) {
                    $contract->purged($now);
                    $contract->backupsPurged($now);

                    return;
                }
                $contract->scheduleDeletion($endedAt->modify('+'.$this->settings->dataRetentionDays($contract->getKind()).' days'));
                $deleteAt = $contract->getDeleteAfterAt();
                if (null === $deleteAt) {
                    return;
                }
                if (null === $contract->getDeletionNoticeAt()) {
                    $contract->scheduleDeletion($now->modify('+7 days'));
                    $deleteAt = $contract->getDeleteAfterAt() ?? $deleteAt;
                    $this->notices->contract($contract, 'deletion-notice', 'Aufbewahrung deines beendeten Systems', 'Die Daten deines beendeten Mietvertrags werden frühestens am '.$deleteAt->format('d.m.Y').' endgültig gelöscht. Bitte sichere benötigte Daten rechtzeitig oder kontaktiere den Betreiber.');
                    $contract->deletionAnnounced($now);

                    return;
                }
                if ($deleteAt > $now || $contract->getDeletionNoticeAt() > $now->modify('-7 days')) {
                    return;
                }
                try {
                    if (null === $contract->getPurgedAt()) {
                        if ([] === $contract->getCleanupJobs()) {
                            $contract->cleanupJobs($this->queue($contract, false));
                        } elseif (ShopProductKind::Proxmox === $contract->getKind() && null === $contract->getError()) {
                            // An explicit operator retry clears the previous error. Uncertain tasks remain locked.
                            $contract->cleanupJobs($this->queue($contract, false));
                        }
                        if ($this->completed($contract->getCleanupJobs())) {
                            $contract->purged($now);
                            $this->finishResource($contract);
                            $this->capacity->release($contract, $now);
                            $this->audit->log(null, 'rental.resource.purged', ['contract_id' => $contract->getId()]);
                        }
                    }
                    $backupDue = $endedAt->modify('+'.(int) $settings['backup_retention_days'].' days');
                    if (null !== $contract->getPurgedAt() && $backupDue <= $now && null === $contract->getBackupsPurgedAt()) {
                        if ([] === $contract->getBackupCleanupJobs()) {
                            $contract->backupCleanupJobs($this->queue($contract, true));
                        }
                        if ($this->completed($contract->getBackupCleanupJobs()) && $this->legacyBackups->run($contract, $now) && $this->prunePlans($contract, $now)) {
                            $contract->backupsPurged($now);
                        }
                    }
                } catch (\DomainException|\InvalidArgumentException $e) {
                    $contract->fail($e->getMessage());
                }
            });
        }
    }

    /** @return list<array{system:string,id:string}> */
    private function queue(ShopContract $contract, bool $backups): array
    {
        $kind = $contract->getKind();
        if (ShopProductKind::Proxmox === $kind) {
            if ($backups || null === $contract->getJobId()) {
                return [];
            }
            $task = $this->proxmoxLifecycle->queue($this->proxmox->installation($contract), 'rental-delete');

            return [['system' => 'proxmox', 'id' => (string) $task->getId()]];
        }
        if (ShopProductKind::Kvm === $kind) {
            $node = $this->em->find(Agent::class, (string) $contract->getConfiguration()['node_id']);
            if (null === $node || ($node->getMetadata()['kvm_managed']['retirement_version'] ?? null) !== 1) {
                throw new \DomainException('KVM_RETIREMENT_AGENT_UPDATE_REQUIRED');
            }
            $job = $this->dispatcher->dispatch($node, 'kvm.managed', ['action' => $backups ? 'vm.purge_backups' : 'vm.delete', 'operation_id' => Uuid::v4()->toRfc4122(), 'vm_id' => $contract->getResourceId(), 'confirm' => '1']);

            return [['system' => 'agent', 'id' => $job->getId()]];
        }
        if ($backups) {
            return [];
        }
        if (ShopProductKind::Gameserver === $kind) {
            $instance = $contract->getOrder()->getInstance();
            if (null === $instance || null === $contract->getJobId()) {
                return [];
            }
            $job = new Job('instance.delete', ['agent_id' => $instance->getNode()->getId(), 'instance_id' => (string) $instance->getId(), 'customer_id' => (string) $instance->getCustomer()->getId(), 'install_path' => $instance->getInstallPath(), 'base_dir' => $instance->getInstanceBaseDir()]);
        } elseif (ShopProductKind::Webspace === $kind) {
            $web = $this->em->find(Webspace::class, (int) $contract->getResourceId());
            if (null === $web) {
                return [];
            }
            $user = $web->getSystemUsername();
            $job = new Job('webspace.delete', ['agent_id' => $web->getNode()->getId(), 'webspace_id' => (string) $web->getId(), 'web_root' => $web->getPath(), 'docroot' => $web->getDocroot(), 'owner_user' => $user, 'owner_group' => $user, 'php_fpm_pool_path' => '/etc/easywi/web/php-fpm/'.$user.'.conf', 'nginx_include_path' => '/etc/easywi/web/nginx/includes/'.$user.'.conf']);
        } else {
            $server = ShopProductKind::Teamspeak3 === $kind ? $this->em->find(Ts3VirtualServer::class, (int) $contract->getResourceId()) : $this->em->find(Ts6VirtualServer::class, (int) $contract->getResourceId());
            if (null === $server) {
                return [];
            }
            if ($server->getSid() < 1) {
                throw new \DomainException('VOICE_UNKNOWN_SERVER_REQUIRES_REVIEW');
            }
            $node = $server->getNode();
            $payload = ['virtual_server_id' => $server->getId(), 'node_id' => $node->getId(), 'sid' => $server->getSid(), 'action' => 'delete', 'query_bind_ip' => $node->getQueryConnectIp(), 'admin_password' => $node->getAdminPassword($this->crypto)];
            if ($node instanceof \App\Module\Core\Domain\Entity\Ts3Node) {
                $payload['query_port'] = $node->getQueryPort();
                $payload['admin_username'] = $node->getAdminUsername();
            } else {
                $payload['query_https_port'] = $node->getQueryHttpsPort();
                $payload['install_dir'] = $node->getInstallPath();
            }
            $job = $this->dispatcher->dispatch($node->getAgent(), ShopProductKind::Teamspeak3 === $kind ? 'ts3.virtual.action' : 'ts6.virtual.action', $payload);

            return [['system' => 'agent', 'id' => $job->getId()]];
        }
        $this->em->persist($job);

        return [['system' => 'core', 'id' => $job->getId()]];
    }

    /** @param list<array{system:string,id:string}> $jobs */
    private function completed(array $jobs): bool
    {
        foreach ($jobs as $job) {
            if ('core' === $job['system']) {
                $status = $this->em->find(Job::class, $job['id'])?->getStatus();
                if (JobStatus::Failed === $status || JobStatus::Cancelled === $status) {
                    throw new \DomainException('CLEANUP_JOB_FAILED');
                } if (JobStatus::Succeeded !== $status) {
                    return false;
                }
            } elseif ('proxmox' === $job['system']) {
                $task = $this->em->find(ProxmoxTask::class, (int) $job['id']);
                if (in_array($task?->getState(), ['failed', 'uncertain'], true)) {
                    throw new \DomainException('PROXMOX_CLEANUP_JOB_REQUIRES_REVIEW');
                }
                if (null === $task || 'succeeded' !== $task->getState() || 'OK' !== $task->getResult()) {
                    return false;
                }
            } else {
                $status = $this->em->find(AgentJob::class, $job['id'])?->getStatus();
                if (AgentJobStatus::Failed === $status) {
                    throw new \DomainException('CLEANUP_JOB_FAILED');
                } if (AgentJobStatus::Success !== $status) {
                    return false;
                }
            }
        }

        return true;
    }

    private function finishResource(ShopContract $contract): void
    {
        $instance = $contract->getOrder()->getInstance();
        if (null !== $instance && null !== $instance->getPortBlockId()) {
            $block = $this->em->find(PortBlock::class, $instance->getPortBlockId());
            if (null !== $block && $block->getInstance()?->getId() === $instance->getId()) {
                $this->em->remove($block);
            }
            $instance->setPortBlockId(null);
        }
        if (ShopProductKind::Webspace === $contract->getKind()) {
            $web = $this->em->find(Webspace::class, (int) $contract->getResourceId());
            if (null !== $web) {
                $web->setStatus(Webspace::STATUS_DELETED);
                $web->setDeletedAt(new \DateTimeImmutable());
            }
        }
    }

    private function prunePlans(ShopContract $contract, \DateTimeImmutable $now): bool
    {
        $module = match ($contract->getKind()) {
            ShopProductKind::Gameserver => 'game', ShopProductKind::Webspace => 'web', default => '',
        };
        if ('' === $module) {
            return true;
        }
        $resource = (string) ($contract->getResourceId() ?? $contract->getOrder()->getInstance()?->getId());
        foreach ($this->plans->all() as $plan) {
            if ($plan->module() !== $module || $plan->resourceId() !== $resource) {
                continue;
            }
            $this->writablePlans->save(new BackupPlan($plan->id(), $plan->module(), $plan->resourceId(), $plan->target(), new RetentionPolicy(0, 1), null, $plan->timeZone(), array_replace($plan->options(), ['retention_enabled' => true])));
            $this->retention->prunePlan($plan->id(), true, $now);
            if ([] !== $this->inventory->forPlan($plan->id())) {
                return false;
            }
        }

        return true;
    }
}
