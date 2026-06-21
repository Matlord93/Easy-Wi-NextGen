<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Enum\MusicbotPlaylistVisibility;
use App\Repository\MusicbotPlaylistRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotPlaylistRepository::class)]
#[ORM\Table(name: 'musicbot_playlists')]
#[ORM\Index(name: 'idx_musicbot_playlists_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_musicbot_playlists_instance', columns: ['instance_id'])]
class MusicbotPlaylist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: MusicbotInstance::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MusicbotInstance $instance = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(enumType: MusicbotPlaylistVisibility::class)]
    private MusicbotPlaylistVisibility $visibility = MusicbotPlaylistVisibility::Private;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $customer, string $name, ?MusicbotInstance $instance = null)
    {
        $this->customer = $customer;
        $this->name = $name;
        $this->instance = $instance;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }
    public function getInstance(): ?MusicbotInstance { return $this->instance; }
    public function setInstance(?MusicbotInstance $instance): void { $this->instance = $instance; $this->touch(); }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; $this->touch(); }
    public function getVisibility(): MusicbotPlaylistVisibility { return $this->visibility; }
    public function setVisibility(MusicbotPlaylistVisibility $visibility): void { $this->visibility = $visibility; $this->touch(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
