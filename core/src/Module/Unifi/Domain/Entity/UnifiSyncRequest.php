<?php

declare(strict_types=1);

namespace App\Module\Unifi\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable coalesced outbox row; written atomically through DBAL during ORM flush. */
#[ORM\Entity]
#[ORM\Table(name: 'unifi_sync_requests')]
class UnifiSyncRequest
{
    #[ORM\Id]
    #[ORM\Column(name: 'instance_id')]
    private int $instanceId;

    #[ORM\Column(options: ['default' => 1])]
    private int $revision = 1;

    #[ORM\Column(name: 'requested_at')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(name: 'available_at')]
    private \DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'claimed_until', nullable: true)]
    private ?\DateTimeImmutable $claimedUntil = null;

    #[ORM\Column(name: 'claim_token', length: 64, nullable: true)]
    private ?string $claimToken = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    public function __construct(int $instanceId)
    {
        $this->instanceId = $instanceId;
        $this->requestedAt = $this->availableAt = new \DateTimeImmutable();
    }
}
