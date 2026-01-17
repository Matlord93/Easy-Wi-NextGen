<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\IncidentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IncidentRepository::class)]
#[ORM\Table(name: 'incidents')]
#[ORM\Index(name: 'idx_incidents_site_id', columns: ['site_id'])]
#[ORM\Index(name: 'idx_incidents_visibility', columns: ['visible_public'])]
#[ORM\Index(name: 'idx_incidents_status', columns: ['status'])]
class Incident
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $siteId;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(length: 40)]
    private string $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column]
    private bool $visiblePublic = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, StatusComponent>
     */
    #[ORM\ManyToMany(targetEntity: StatusComponent::class)]
    #[ORM\JoinTable(name: 'incident_components')]
    #[ORM\JoinColumn(name: 'incident_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'status_component_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Collection $affectedComponents;

    public function __construct(
        int $siteId,
        string $title,
        string $status,
        \DateTimeImmutable $startedAt,
        ?string $message = null,
        bool $visiblePublic = false,
        ?\DateTimeImmutable $resolvedAt = null,
    ) {
        $this->siteId = $siteId;
        $this->title = $title;
        $this->status = $status;
        $this->startedAt = $startedAt;
        $this->message = $message;
        $this->visiblePublic = $visiblePublic;
        $this->resolvedAt = $resolvedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->affectedComponents = new ArrayCollection();
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): void
    {
        $this->message = $message;
        $this->touch();
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
        $this->touch();
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): void
    {
        $this->resolvedAt = $resolvedAt;
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

    /**
     * @return Collection<int, StatusComponent>
     */
    public function getAffectedComponents(): Collection
    {
        return $this->affectedComponents;
    }

    /**
     * @param iterable<StatusComponent> $components
     */
    public function syncAffectedComponents(iterable $components): void
    {
        $this->affectedComponents->clear();
        foreach ($components as $component) {
            if (!$this->affectedComponents->contains($component)) {
                $this->affectedComponents->add($component);
            }
        }
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
