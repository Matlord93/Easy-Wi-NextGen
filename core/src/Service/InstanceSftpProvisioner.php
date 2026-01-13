<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Instance;
use App\Entity\InstanceSftpCredential;
use App\Entity\Job;
use App\Entity\User;
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
        $password = bin2hex(random_bytes(12));
        $encryptedPassword = $this->encryptionService->encrypt($password);

        $credential = new InstanceSftpCredential($instance, $username, $encryptedPassword);
        $this->entityManager->persist($credential);

        $job = new Job('instance.sftp.credentials.reset', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'username' => $username,
            'password' => $password,
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'instance.sftp.credentials.reset_requested', [
            'instance_id' => $instance->getId(),
            'customer_id' => $instance->getCustomer()->getId(),
            'job_id' => $job->getId(),
            'username' => $username,
            'source' => 'instance.provisioning',
        ]);

        return $job;
    }
}
