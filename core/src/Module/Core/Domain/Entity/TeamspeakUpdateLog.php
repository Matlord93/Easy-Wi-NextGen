<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\TeamspeakUpdateLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamspeakUpdateLogRepository::class)]
#[ORM\Table(name: 'teamspeak_update_logs')]
class TeamspeakUpdateLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 8)]
    private string $instanceType;
    #[ORM\Column]
    private int $instanceId;
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $oldVersion;
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $targetVersion;
    #[ORM\Column(length: 16)]
    private string $status = 'pending';
    #[ORM\Column]
    private \DateTimeImmutable $startedAt;
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $executedBy;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $backupPath = null;
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $downloadUrl = null;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorDetails = null;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $steps = [];

    public function __construct(string $instanceType, int $instanceId, ?string $oldVersion, ?string $targetVersion, User $executedBy)
    { $this->instanceType=$instanceType; $this->instanceId=$instanceId; $this->oldVersion=$oldVersion; $this->targetVersion=$targetVersion; $this->executedBy=$executedBy; $this->startedAt=new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function setEndedAt(?\DateTimeImmutable $endedAt): void { $this->endedAt = $endedAt; }
    public function setBackupPath(?string $backupPath): void { $this->backupPath = $backupPath; }
    public function setDownloadUrl(?string $downloadUrl): void { $this->downloadUrl = $downloadUrl; }
    public function setErrorMessage(?string $errorMessage): void { $this->errorMessage = $errorMessage; }
    public function setErrorDetails(?string $errorDetails): void { $this->errorDetails = $errorDetails; }
    public function addStep(string $step, string $status = 'ok', ?string $message = null): void { $steps = $this->steps ?? []; $steps[] = ['at'=>(new \DateTimeImmutable())->format(DATE_ATOM),'step'=>$step,'status'=>$status,'message'=>$message]; $this->steps = $steps; }
    public function toArray(): array { return ['id'=>$this->id,'instance_type'=>$this->instanceType,'instance_id'=>$this->instanceId,'old_version'=>$this->oldVersion,'target_version'=>$this->targetVersion,'status'=>$this->status,'started_at'=>$this->startedAt->format(DATE_ATOM),'ended_at'=>$this->endedAt?->format(DATE_ATOM),'backup_path'=>$this->backupPath,'download_url'=>$this->downloadUrl,'error_message'=>$this->errorMessage,'steps'=>$this->steps ?? []]; }
}
