<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Enum\ConnectionPolicy;

class DatabaseProvisioningService
{
    public function __construct(
        private readonly EncryptionService $encryptionService,
    ) {
    }
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_ALLOWED_HOSTS = '%';
    private const DEFAULT_CONNECTION_POLICY = ConnectionPolicy::Private;

    /** @return Job[] */
    public function buildCreateJobs(Database $database, string $agentId): array
    {
        return [$this->createJob('database.create', $this->buildPayload($database, $agentId))];
    }

    public function buildPasswordRotateJob(Database $database, string $agentId): Job
    {
        return $this->createJob('database.rotate_password', $this->buildPayload($database, $agentId));
    }

    public function buildDeleteJob(Database $database, string $agentId): Job
    {
        return $this->createJob('database.delete', $this->buildPayload($database, $agentId));
    }

    /** @return array<string,string> */
    private function buildPayload(Database $database, string $agentId): array
    {
        $payload = [
            'database_id' => (string) ($database->getId() ?? ''),
            'customer_id' => (string) $database->getCustomer()->getId(),
            'engine' => strtolower($database->getEngine()),
            'host' => $database->getHost(),
            'port' => (string) $database->getPort(),
            'database' => $database->getName(),
            'username' => $database->getUsername(),
            'allowed_hosts' => self::DEFAULT_ALLOWED_HOSTS,
            // Metadata only: enforcement for network access is handled outside DB self-service jobs.
            'connection_policy' => self::DEFAULT_CONNECTION_POLICY->value,
            'agent_id' => $agentId,
        ];

        $node = $database->getNode();
        if ($node instanceof DatabaseNode) {
            $payload['database_node_id'] = (string) $node->getId();
            $payload['tls_mode'] = $node->getTlsMode();
            if ($node->getCaCert() !== null && trim($node->getCaCert()) !== '') {
                $payload['ca_cert'] = $node->getCaCert();
            }

            $adminUser = trim((string) $node->getAdminUser());
            if ($adminUser !== '') {
                $payload['admin_user'] = $adminUser;
            }

            $encryptedAdminSecret = $node->getEncryptedAdminSecret();
            if (is_array($encryptedAdminSecret) && $encryptedAdminSecret !== []) {
                $payload['admin_secret'] = $this->encryptionService->decrypt($encryptedAdminSecret);
            }
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
