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
        $oneTimePassword = bin2hex(random_bytes(18));
        $installPath = $this->resolveInstallPath($instance);
        $credential = new InstanceSftpCredential($instance, $username, $this->encryptionService->encrypt($oneTimePassword));
        $credential->setRotatedAt(null);
        $credential->setExpiresAt((new \DateTimeImmutable('+15 minutes'))->setTimezone(new \DateTimeZone('UTC')));
        $credential->setRootPath($installPath);
        $credential->markProvisioningPending();
        $this->entityManager->persist($credential);
        $this->entityManager->flush();

        $job = new Job('instance.sftp.credentials.reset', [
            'instance_id' => (string) $instance->getId(),
            'customer_id' => (string) $instance->getCustomer()->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'node_id' => $instance->getNode()->getId(),
            'credential_id' => $credential->getId(),
            'username' => $username,
            'one_time_password_secret' => $this->encryptionService->encrypt($oneTimePassword),
            'install_path' => $installPath,
            'base_dir' => $this->resolveBaseDir($instance, $installPath),
            'root_path' => $installPath,
            'expires_at' => $credential->getExpiresAt()?->format(DATE_RFC3339),
            'os_type' => $this->resolveAgentOsType($instance),
            'preferred_backend' => $this->resolveAgentOsType($instance) === 'windows' ? 'WINDOWS_OPENSSH_SFTP' : 'PROFTPD_SFTP',
            'rotate' => true,
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

    private function resolveInstallPath(Instance $instance): string
    {
        $installPath = trim((string) $instance->getInstallPath());
        if ($installPath !== '') {
            return $installPath;
        }

        return $this->resolveBaseDir($instance, '') . '/gs' . preg_replace('/[^a-z0-9]/i', '', (string) ($instance->getId() ?? 'instance'));
    }

    private function resolveBaseDir(Instance $instance, string $installPath): string
    {
        $baseDir = trim((string) ($instance->getInstanceBaseDir() ?? ''));
        if ($baseDir !== '') {
            return rtrim($baseDir, '/\\');
        }

        if ($installPath !== '') {
            $dirname = dirname($installPath);
            if ($dirname !== '' && $dirname !== '.') {
                return $dirname;
            }
        }

        return '/srv';
    }

    private function resolveAgentOsType(Instance $instance): string
    {
        $stats = $instance->getNode()->getLastHeartbeatStats();
        $os = is_array($stats) && is_string($stats['os'] ?? null) ? strtolower((string) $stats['os']) : '';
        if ($os === 'windows') {
            return 'windows';
        }

        $metadata = $instance->getNode()->getMetadata();
        $metaOs = is_array($metadata) && is_string($metadata['os_type'] ?? null) ? strtolower((string) $metadata['os_type']) : '';

        return $metaOs !== '' ? $metaOs : 'linux';
    }

}
