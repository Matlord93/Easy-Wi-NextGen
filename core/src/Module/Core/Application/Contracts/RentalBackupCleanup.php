<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Infrastructure\Config\JsonMap;
use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Domain\Entity\Backup;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Entity\Ts3VirtualServer;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Enum\BackupStatus;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\ShopProductKind;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RentalBackupCleanup
{
    public function __construct(private EntityManagerInterface $em, private EncryptionServiceInterface $encryption)
    {
    }

    public function run(ShopContract $contract, \DateTimeImmutable $now): bool
    {
        if (in_array($contract->getKind(), [ShopProductKind::Teamspeak3, ShopProductKind::Teamspeak6], true)) {
            // Shared host-instance backups are NOT virtual-server snapshots. Never delete a host backup using a virtual-server ID.
            $server = ShopProductKind::Teamspeak3 === $contract->getKind() ? $this->em->find(Ts3VirtualServer::class, (int) $contract->getResourceId()) : $this->em->find(Ts6VirtualServer::class, (int) $contract->getResourceId());
            if (null === $server) {
                return true;
            }
            $prefix = $server instanceof Ts3VirtualServer ? 'ts3' : 'ts6';
            /** @var iterable<AgentJob> $rows */
            $rows = $this->em->getRepository(AgentJob::class)->createQueryBuilder('j')->where('j.node = :node AND j.type IN (:types) AND j.status IN (:statuses)')->setParameter('node', $server->getNode()->getAgent()->getId())->setParameter('types', [$prefix.'.virtual.snapshot.create', $prefix.'.virtual.snapshot.restore'])->setParameter('statuses', [AgentJobStatus::Success->value, AgentJobStatus::Failed->value])->getQuery()->toIterable();
            foreach ($rows as $job) {
                $payload = $job->getPayload();
                if ((int) ($payload['virtual_server_id'] ?? 0) === $server->getId() && (int) ($payload['node_id'] ?? 0) === $server->getNode()->getId()) {
                    $job->forgetVoiceSnapshot();
                }
            }

            return true;
        }
        if (ShopProductKind::Gameserver !== $contract->getKind()) {
            return true;
        }
        $instance = $contract->getOrder()->getInstance();
        if (null === $instance) {
            return true;
        }
        $complete = true;
        foreach ($this->em->getRepository(BackupDefinition::class)->findBy(['customer' => $contract->getCustomer(), 'targetType' => BackupTargetType::Game, 'targetId' => (string) $instance->getId()]) as $definition) {
            $backups = $this->em->getRepository(Backup::class)->findBy(['definition' => $definition, 'deletedAt' => null], ['id' => 'ASC'], 100);
            if (100 === count($backups)) {
                $complete = false;
            }
            foreach ($backups as $backup) {
                if (in_array($backup->getStatus(), [BackupStatus::Queued, BackupStatus::Running], true)) {
                    $complete = false;
                    continue;
                }
                $path = $backup->getArchivePath();
                if (null === $path) {
                    $backup->markDeleted($now);
                    continue;
                }
                $job = $backup->getCleanupJob();
                if (null !== $job) {
                    if (JobStatus::Succeeded === $job->getStatus()) {
                        $backup->markDeleted($now);
                    } elseif (in_array($job->getStatus(), [JobStatus::Failed, JobStatus::Cancelled], true)) {
                        throw new \DomainException('BACKUP_CLEANUP_JOB_FAILED');
                    } else {
                        $complete = false;
                    }
                    continue;
                }
                if (null === $backup->getChecksumSha256() || null === $backup->getSizeBytes()) {
                    throw new \DomainException('BACKUP_CLEANUP_PROOF_MISSING');
                }
                $other = (int) $this->em->getRepository(Backup::class)->createQueryBuilder('b')->select('COUNT(b.id)')->where('b.archivePath = :path AND b.id <> :id AND b.deletedAt IS NULL')->setParameter('path', $path)->setParameter('id', $backup->getId())->getQuery()->getSingleScalarResult();
                if ($other > 0) {
                    throw new \DomainException('BACKUP_ARCHIVE_SHARED_REQUIRES_REVIEW');
                }
                $target = $definition->getBackupTarget();
                $payload = ['agent_id' => $instance->getNode()->getId(), 'instance_id' => (string) $instance->getId(), 'backup_id' => (string) $backup->getId(), 'backup_path' => $path, 'backup_sha256' => $backup->getChecksumSha256(), 'size_bytes' => (string) $backup->getSizeBytes(), 'backup_target_type' => $target?->getType()->value ?? 'local'];
                if (null !== $target) {
                    $payload['backup_target_config'] = $target->getConfig();
                    $secrets = [];
                    foreach (array_keys($target->getEncryptedCredentials()) as $key) {
                        if (!is_string($key) || !in_array($key, ['password', 'token', 'username'], true)) {
                            continue;
                        }
                        $encrypted = JsonMap::strings($target->getEncryptedCredentials()[$key]);
                        if (isset($encrypted['key_id'],$encrypted['nonce'],$encrypted['ciphertext'])) {
                            $secrets[$key] = $this->encryption->decrypt($encrypted);
                        }
                    }
                    $payload['backup_target_secret'] = $secrets;
                }
                if (($instance->getNode()->getMetadata()['rental_backup_delete_version'] ?? null) !== 1) {
                    throw new \DomainException('BACKUP_RETENTION_AGENT_UPDATE_REQUIRED');
                }
                $job = new Job('instance.backup.delete', $payload);
                $this->em->persist($job);
                $backup->setCleanupJob($job);
                $complete = false;
            }
        }

        return $complete;
    }
}
