<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Replay records are shared by all PHP workers and survive cache clears/releases.
 * @psalm-api
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_request_nonces')]
#[ORM\Index(name: 'idx_agent_request_nonces_expiry', columns: ['expires_at'])]
class AgentRequestNonce
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $nonceKey;

    #[ORM\Column(type: 'bigint')]
    private int $expiresAt;

    public function __construct(string $nonceKey, int $expiresAt)
    {
        $this->nonceKey = $nonceKey;
        $this->expiresAt = $expiresAt;
    }
}
