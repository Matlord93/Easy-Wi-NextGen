<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\JobResultStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'job_results')]
class JobResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'result', targetEntity: Job::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Job $job;

    #[ORM\Column(enumType: JobResultStatus::class)]
    private JobResultStatus $status;

    #[ORM\Column(type: 'json')]
    private array $output;

    #[ORM\Column]
    private \DateTimeImmutable $completedAt;

    public function __construct(Job $job, JobResultStatus $status, array $output, ?\DateTimeImmutable $completedAt = null)
    {
        $this->job = $job;
        $this->status = $status;
        $this->output = $output;
        $this->completedAt = $completedAt ?? new \DateTimeImmutable();
        $job->attachResult($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function getStatus(): JobResultStatus
    {
        return $this->status;
    }

    public function getOutput(): array
    {
        return $this->output;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }
}
