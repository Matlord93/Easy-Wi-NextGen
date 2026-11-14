<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine hydrates and proxies this entity; Twig also calls its public accessors.
 *
 * @psalm-api
 */
#[ORM\Entity]
#[ORM\Table(name: 'proxmox_tasks')]
#[ORM\UniqueConstraint(name: 'uniq_proxmox_request', columns: ['cluster_id', 'request_key'])]
#[ORM\UniqueConstraint(name: 'uniq_proxmox_guest_lock', columns: ['cluster_id', 'guest_lock'])]
#[ORM\Index(name: 'idx_proxmox_task_state', columns: ['state'])]
class ProxmoxTask
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    private ProxmoxCluster $cluster;
    #[ORM\Column(length: 64)]
    private string $node;
    #[ORM\Column(length: 10)]
    private string $kind;
    #[ORM\Column]
    private int $vmId;
    #[ORM\Column(length: 40)]
    private string $action;
    #[ORM\Column(length: 32)]
    private string $requestKey;
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $upid = null;
    #[ORM\Column(length: 20)]
    private string $state = 'submitting';
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $guestLock;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $result = null;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;
    #[ORM\Column(nullable: true)]
    private ?int $actorId;

    public function __construct(ProxmoxCluster $cluster, string $node, string $kind, int $vmId, string $action, string $requestKey, ?int $actorId)
    {
        $this->cluster = $cluster;
        $this->node = $node;
        $this->kind = $kind;
        $this->vmId = $vmId;
        $this->guestLock = $kind.':'.$vmId;
        $this->action = $action;
        $this->requestKey = $requestKey;
        $this->actorId = $actorId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function dispatched(?string $upid): void
    {
        if (null === $upid) {
            $this->finish(true, 'OK');

            return;
        }
        if (!str_starts_with($upid, 'UPID:') || strlen($upid) > 512) {
            throw new \RuntimeException('Invalid Proxmox task ID.');
        }
        $this->upid = $upid;
        $this->state = 'running';
    }

    public function finish(bool $success, string $result): void
    {
        $this->state = $success ? 'succeeded' : 'failed';
        $this->guestLock = null;
        $this->result = $result;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /** Retain the exclusive VM claim until the upstream outcome is reviewed. */
    public function uncertain(): void
    {
        $this->state = 'uncertain';
        $this->result = 'dispatch_unknown';
    }

    public function isActive(): bool
    {
        return in_array($this->state, ['submitting', 'running', 'uncertain'], true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCluster(): ProxmoxCluster
    {
        return $this->cluster;
    }

    public function getNode(): string
    {
        return $this->node;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getVmId(): int
    {
        return $this->vmId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getRequestKey(): string
    {
        return $this->requestKey;
    }

    public function getUpid(): ?string
    {
        return $this->upid;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getActorId(): ?int
    {
        return $this->actorId;
    }
}
