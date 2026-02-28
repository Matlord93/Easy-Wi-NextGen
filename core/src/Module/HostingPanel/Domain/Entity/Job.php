<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Domain\Entity;

use App\Module\HostingPanel\Domain\Enum\JobStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hp_job')]
#[ORM\UniqueConstraint(name: 'uniq_hp_job_idempotency', columns: ['idempotency_key'])]
class Job
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $type;

    #[ORM\Column(enumType: JobStatus::class)]
    private JobStatus $status = JobStatus::Queued;

    #[ORM\Column(length: 128, name: 'idempotency_key')]
    private string $idempotencyKey;

    #[ORM\Column(type: 'json')]
    private array $payload = [];

    #[ORM\ManyToOne(targetEntity: Node::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Node $node;

    /** @var Collection<int, JobRun> */
    #[ORM\OneToMany(mappedBy: 'job', targetEntity: JobRun::class, cascade: ['persist'])]
    private Collection $runs;

    public function __construct(Node $node, string $type, string $idempotencyKey, array $payload)
    {
        $this->node = $node;
        $this->type = $type;
        $this->idempotencyKey = $idempotencyKey;
        $this->payload = $payload;
        $this->runs = new ArrayCollection();
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function setStatus(JobStatus $status): void
    {
        $this->status = $status;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
