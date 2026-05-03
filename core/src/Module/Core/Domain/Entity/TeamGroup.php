<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\TeamGroupRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamGroupRepository::class)]
#[ORM\Table(name: 'team_groups')]
#[ORM\Index(name: 'idx_team_groups_site_sort', columns: ['site_id', 'sort_order'])]
class TeamGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\Column(length: 140)]
    private string $name;

    #[ORM\Column(length: 140)]
    private string $game;

    #[ORM\Column(length: 180, unique: false)]
    private string $slug;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function __construct(Site $site, string $name, string $game, string $slug)
    {
        $this->site = $site;
        $this->name = trim($name);
        $this->game = trim($game);
        $this->slug = trim($slug);
    }

    public function getId(): ?int { return $this->id; }
    public function getSite(): Site { return $this->site; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = trim($name); }
    public function getGame(): string { return $this->game; }
    public function setGame(string $game): void { $this->game = trim($game); }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = trim($slug); }
    public function getImagePath(): ?string { return $this->imagePath; }
    public function setImagePath(?string $imagePath): void { $this->imagePath = $imagePath === null ? null : trim($imagePath); }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): void { $this->sortOrder = $sortOrder; }
}
