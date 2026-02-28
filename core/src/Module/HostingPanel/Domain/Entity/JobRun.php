<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Domain\Entity;

use App\Module\HostingPanel\Domain\Enum\JobStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hp_job_run')]
class JobRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Job::class, inversedBy: 'runs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Job $job;

    #[ORM\Column(enumType: JobStatus::class)]
    private JobStatus $status;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'json')]
    private array $result = [];

    public function __construct(Job $job)
    {
        $this->job = $job;
        $this->status = JobStatus::Queued;
    }
}
