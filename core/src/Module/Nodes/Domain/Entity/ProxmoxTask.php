<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine hydrates and proxies this entity; Twig also calls its public accessors.
 *
 * @psalm-api
 * @psalm-type RentalResources = array{cores:int, memory_mib:int, disk_gib:int}
 * @psalm-type InstallationPasswordSetup = array{ciphertext: string, hash_ciphertext: string, requested_at: int, attempted_at: int}
 * @psalm-type Installation = array{hostname: string, username: string, keys: list<string>, phase: string, customer_id: ?int, clone_submitted?: true, rental_id?: int, resources?: RentalResources, prepare_root_password?: 'required'|'if-supported', started_at?: int, root_password_setup?: InstallationPasswordSetup}
 * @psalm-type Reinstallation = array{phase: string, assignment_id: int, tag: string, product_id: int, digest: string, configuration: string, original_volume: string, device: string, device_options: string, disk_gib: int, source_volume: string, source_digest: string, import_serial?: string, replacement_volume?: string, started_at?: int, removal_requested_at?: int, step_submitted_at?: int, install_password_hash?: string, password_requested_at?: int, rollback_requested_at?: int}
 */
#[ORM\Entity]
#[ORM\Table(name: 'proxmox_tasks')]
#[ORM\UniqueConstraint(name: 'uniq_proxmox_request', columns: ['cluster_id', 'request_key'])]
#[ORM\UniqueConstraint(name: 'uniq_proxmox_guest_lock', columns: ['cluster_id', 'guest_lock'])]
#[ORM\UniqueConstraint(name: 'uniq_proxmox_target_lock', columns: ['cluster_id', 'target_lock'])]
#[ORM\Index(name: 'idx_proxmox_task_state', columns: ['state'])]
#[ORM\Index(name: 'idx_pve_rental_origin_action', columns: ['origin_task_id', 'action'])]
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
    #[ORM\Column(nullable: true)]
    private ?int $targetVmId = null;
    #[ORM\Column(nullable: true)]
    private ?int $targetLock = null;
    #[ORM\Column(nullable: true)]
    private ?int $originTaskId = null;
    /** @var Installation|array{reinstall: Reinstallation}|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $installation = null;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastPolledAt = null;
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
        if (isset($this->installation['root_password_setup'])) {
            unset($this->installation['root_password_setup']);
        }
        $this->state = $success ? 'succeeded' : 'failed';
        $this->guestLock = null;
        $this->targetLock = null;
        $this->result = $result;
        $this->finishedAt = new \DateTimeImmutable();
    }

    /** Retain the exclusive VM claim until the upstream outcome is reviewed. */
    public function uncertain(string $reason = 'dispatch_unknown'): void
    {
        if (isset($this->installation['root_password_setup'])) {
            unset($this->installation['root_password_setup']);
        }
        $this->state = 'uncertain';
        $this->result = $reason;
    }

    public function isActive(): bool
    {
        return in_array($this->state, ['queued', 'submitting', 'running', 'uncertain'], true);
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

    public function getTargetVmId(): ?int
    {
        return $this->targetVmId;
    }

    public function getOriginTaskId(): ?int
    {
        return $this->originTaskId;
    }

    public function submittedOn(string $node): void
    {
        $this->beginQueuedSubmission();
        $this->node = $node;
    }

    /** @return Installation|null */
    public function getInstallation(): ?array
    {
        $settings = $this->installation;
        if (isset($settings['reinstall'])) {
            return null;
        }
        /** @var Installation|null $settings */
        return $settings;
    }

    /** @return Reinstallation|null */
    public function getReinstallation(): ?array
    {
        return $this->installation['reinstall'] ?? null;
    }

    /** @param Reinstallation $plan */
    public function beginReinstallationStep(array $plan): void
    {
        $this->installation = ['reinstall' => $plan];
        $this->upid = null;
        $this->state = 'submitting';
    }

    public function reinstallationStepDispatched(?string $upid): void
    {
        if (null === $upid) {
            $this->state = 'running';

            return;
        }
        $this->dispatched($upid);
    }

    /** @param Reinstallation $plan */
    public function queueReinstallationRollback(array $plan): void
    {
        if ('uncertain' !== $this->state) {
            throw new \LogicException('Only an uncertain reinstallation can be queued for rollback.');
        }
        $this->beginReinstallationStep($plan);
        $this->reinstallationStepDispatched(null);
        $this->result = null;
    }

    public function installationPhase(string $phase): void
    {
        if (!isset($this->installation['hostname'])) {
            throw new \LogicException('Task is not a VM installation.');
        }
        $this->installation['phase'] = $phase;
    }

    public function beginInstallationStart(): void
    {
        $this->beginInstallationStep('starting');
        $this->installation['started_at'] = time();
    }

    public function beginInstallationResize(): void
    {
        $this->beginInstallationStep('resizing');
    }

    /** @param InstallationPasswordSetup $setup */
    public function recordInstallationPasswordAttempt(#[\SensitiveParameter] array $setup): void
    {
        $this->beginInstallationStep('setting_password');
        $this->installation['root_password_setup'] = $setup;
    }

    private function beginInstallationStep(string $phase): void
    {
        $this->installationPhase($phase);
        $this->upid = null;
        $this->state = 'submitting';
    }

    public function beginInstallationClone(): void
    {
        if (!isset($this->installation['hostname'])) {
            throw new \LogicException('Missing Proxmox installation settings.');
        }
        $this->beginQueuedSubmission();
        $this->installation['clone_submitted'] = true;
    }

    private function beginQueuedSubmission(): void
    {
        if ('queued' !== $this->state) {
            throw new \LogicException('Only a queued Proxmox task can begin submission.');
        }
        $this->state = 'submitting';
        $this->lastPolledAt = new \DateTimeImmutable();
    }

    public function polled(\DateTimeImmutable $at): void
    {
        $this->lastPolledAt = $at;
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
