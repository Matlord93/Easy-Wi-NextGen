<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\InstanceSftpCredential;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Repository\InstanceSftpCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;

final class InstanceSftpProvisioner
{
    public function __construct(
        private readonly InstanceSftpCredentialRepository $instanceSftpCredentialRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function provision(User $actor, Instance $instance): ?Job
    {
        $existing = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
        if ($existing !== null) {
            return null;
        }

        $username = sprintf('sftp%d', $instance->getId());
        $credential = new InstanceSftpCredential($instance, $username, $this->encryptionService->encrypt(bin2hex(random_bytes(24))));
        $credential->setRotatedAt(null);
        $credential->setExpiresAt((new \DateTimeImmutable('+30 days'))->setTimezone(new \DateTimeZone('UTC')));
        $this->entityManager->persist($credential);
        $this->entityManager->flush();

        $job = new Job('instance.sftp.credentials.reset', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'credential_id' => $credential->getId(),
            'username' => $username,
            'rotate' => true,
            'expires_at' => $credential->getExpiresAt()?->format(DATE_RFC3339),
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'instance.sftp.credentials.reset_requested', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'job_id' => $job->getId(),
            'username' => $username,
            'credential_id' => $credential->getId(),
            'source' => 'instance.provisioning',
        ]);

        return $job;
    }
}
