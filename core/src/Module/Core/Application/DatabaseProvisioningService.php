<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\Job;

final class DatabaseProvisioningService
{
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_PRIVILEGES = 'SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX';

    /**
     * @return Job[]
     */
    public function buildCreateJobs(Database $database, array $encryptedPassword, string $agentId): array
    {
        $payload = $this->buildPayload($database, $encryptedPassword, $agentId);

        $jobs = [
            $this->createJob('database.create', $payload),
            $this->createJob('database.user.create', $payload),
            $this->createJob('database.grant.apply', array_merge($payload, ['privileges' => self::DEFAULT_PRIVILEGES])),
        ];

        return $jobs;
    }

    public function buildPasswordRotateJob(Database $database, array $encryptedPassword, string $agentId): Job
    {
        $payload = $this->buildPayload($database, $encryptedPassword, $agentId);

        return $this->createJob('database.password.rotate', $payload);
    }

    public function buildDeleteJob(Database $database, string $agentId): Job
    {
        $payload = $this->buildPayload($database, $database->getEncryptedPassword(), $agentId);

        return $this->createJob('database.delete', $payload);
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedPassword
     * @return array<string, string>
     */
    private function buildPayload(Database $database, array $encryptedPassword, string $agentId): array
    {
        $payload = [
            'database_id' => (string) ($database->getId() ?? ''),
            'customer_id' => (string) $database->getCustomer()->getId(),
            'engine' => $database->getEngine(),
            'host' => $database->getHost(),
            'port' => (string) $database->getPort(),
            'database' => $database->getName(),
            'username' => $database->getUsername(),
            'encrypted_password' => $encryptedPassword,
            'agent_id' => $agentId,
        ];

        if ($database->getNode() !== null) {
            $payload['database_node_id'] = (string) $database->getNode()?->getId();
        }

        return $payload;
    }

    private function createJob(string $type, array $payload): Job
    {
        $job = new Job($type, $payload);
        $job->setMaxAttempts(self::DEFAULT_MAX_ATTEMPTS);

        return $job;
    }
}
