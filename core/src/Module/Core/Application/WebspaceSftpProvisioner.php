<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Entity\WebspaceSftpCredential;
use App\Repository\WebspaceSftpCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;

final class WebspaceSftpProvisioner
{
    public function __construct(
        private readonly WebspaceSftpCredentialRepository $credentialRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function ensureCredential(User $actor, Webspace $webspace): WebspaceSftpCredential
    {
        $existing = $this->credentialRepository->findOneByWebspace($webspace);
        if ($existing !== null) {
            return $existing;
        }

        $username = $webspace->getSystemUsername();
        if ($username === '') {
            $username = sprintf('ws%d', $webspace->getId());
        }

        $password = bin2hex(random_bytes(12));
        $encryptedPassword = $this->encryptionService->encrypt($password);

        $credential = new WebspaceSftpCredential($webspace, $username, $encryptedPassword);
        $this->entityManager->persist($credential);

        $job = new Job('webspace.sftp.credentials.reset', [
            'webspace_id' => (string) $webspace->getId(),
            'customer_id' => (string) $webspace->getCustomer()->getId(),
            'agent_id' => $webspace->getNode()->getId(),
            'username' => $username,
            'password' => $password,
            'root_path' => $webspace->getPath(),
        ]);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'webspace.sftp.credentials.reset_requested', [
            'webspace_id' => $webspace->getId(),
            'customer_id' => $webspace->getCustomer()->getId(),
            'job_id' => $job->getId(),
            'username' => $username,
            'source' => 'webspace.provisioning',
        ]);

        $this->entityManager->flush();

        return $credential;
    }
}
