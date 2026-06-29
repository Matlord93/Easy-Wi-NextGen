<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Repository\MusicbotRadioFavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotRadioFavoriteRepository::class)]
#[ORM\Table(name: 'musicbot_radio_favorites')]
#[ORM\UniqueConstraint(name: 'uniq_radio_favorite_customer_station', columns: ['customer_id', 'station_id'])]
#[ORM\Index(name: 'idx_radio_favorites_customer', columns: ['customer_id'])]
class MusicbotRadioFavorite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: MusicbotRadioStation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotRadioStation $station;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $customer, MusicbotRadioStation $station)
    {
        $this->customer = $customer;
        $this->station = $station;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }
    public function getStation(): MusicbotRadioStation { return $this->station; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
