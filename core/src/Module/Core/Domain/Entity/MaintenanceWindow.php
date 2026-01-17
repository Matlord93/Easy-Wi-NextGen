<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\MaintenanceWindowRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MaintenanceWindowRepository::class)]
#[ORM\Table(name: 'maintenance_windows')]
#[ORM\Index(name: 'idx_maintenance_windows_site_id', columns: ['site_id'])]
#[ORM\Index(name: 'idx_maintenance_windows_visibility', columns: ['visible_public'])]
#[ORM\Index(name: 'idx_maintenance_windows_start_end', columns: ['start_at', 'end_at'])]
class MaintenanceWindow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $siteId;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column]
    private \DateTimeImmutable $startAt;

    #[ORM\Column]
    private \DateTimeImmutable $endAt;

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
    #[ORM\JoinTable(name: 'maintenance_window_components')]
    #[ORM\JoinColumn(name: 'maintenance_window_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'status_component_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Collection $affectedComponents;

    public function __construct(
        int $siteId,
        string $title,
        \DateTimeImmutable $startAt,
        \DateTimeImmutable $endAt,
        ?string $message = null,
        bool $visiblePublic = false,
    ) {
        $this->siteId = $siteId;
        $this->title = $title;
        $this->startAt = $startAt;
        $this->endAt = $endAt;
        $this->message = $message;
        $this->visiblePublic = $visiblePublic;
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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): void
    {
        $this->message = $message;
        $this->touch();
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): void
    {
        $this->startAt = $startAt;
        $this->touch();
    }

    public function getEndAt(): \DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): void
    {
        $this->endAt = $endAt;
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
