<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StatusComponentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StatusComponentRepository::class)]
#[ORM\Table(name: 'status_components')]
#[ORM\Index(name: 'idx_status_components_site_id', columns: ['site_id'])]
#[ORM\Index(name: 'idx_status_components_visibility', columns: ['visible_public'])]
class StatusComponent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $siteId;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $targetRef;

    #[ORM\Column(length: 40)]
    private string $status;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column]
    private bool $visiblePublic = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        int $siteId,
        string $name,
        string $type,
        string $targetRef,
        string $status,
        bool $visiblePublic = false,
        ?\DateTimeImmutable $lastCheckedAt = null,
    ) {
        $this->siteId = $siteId;
        $this->name = $name;
        $this->type = $type;
        $this->targetRef = $targetRef;
        $this->status = $status;
        $this->visiblePublic = $visiblePublic;
        $this->lastCheckedAt = $lastCheckedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSiteId(): int
    {
        return $this->siteId;
    }

    public function setSiteId(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->touch();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->touch();
    }

    public function getTargetRef(): string
    {
        return $this->targetRef;
    }

    public function setTargetRef(string $targetRef): void
    {
        $this->targetRef = $targetRef;
        $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): void
    {
        $this->lastCheckedAt = $lastCheckedAt;
        $this->touch();
    }

    public function isVisiblePublic(): bool
    {
        return $this->visiblePublic;
    }

    public function setVisiblePublic(bool $visiblePublic): void
    {
        $this->visiblePublic = $visiblePublic;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
