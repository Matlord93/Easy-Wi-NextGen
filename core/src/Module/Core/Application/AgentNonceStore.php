<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class AgentNonceStore
{
    public function __construct(private Connection $connection)
    {
    }

    /** The primary key makes consumption atomic across processes and panel hosts. */
    public function consume(string $agentId, string $nonce, int $expiresAt): bool
    {
        $this->connection->executeStatement('DELETE FROM agent_request_nonces WHERE expires_at < :now', ['now' => time()]);
        try {
            $this->connection->insert('agent_request_nonces', [
                'nonce_key' => hash('sha256', $agentId."\0".$nonce),
                'expires_at' => $expiresAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
