<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JobLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JobLogRepository::class)]
#[ORM\Table(name: 'job_logs')]
#[ORM\Index(name: 'idx_job_logs_job', columns: ['job_id'])]
class JobLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Job::class)]
    #[ORM\JoinColumn(name: 'job_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Job $job;

    #[ORM\Column(length: 255)]
    private string $message;

    #[ORM\Column(nullable: true)]
    private ?int $progress;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Job $job, string $message, ?int $progress = null)
    {
        $this->job = $job;
        $this->message = $message;
        $this->progress = $progress;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getProgress(): ?int
    {
        return $this->progress;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
