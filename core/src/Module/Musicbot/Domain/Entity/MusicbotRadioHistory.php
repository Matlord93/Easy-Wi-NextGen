<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\User;
use App\Repository\MusicbotRadioHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotRadioHistoryRepository::class)]
#[ORM\Table(name: 'musicbot_radio_history')]
#[ORM\Index(name: 'idx_radio_history_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_radio_history_station', columns: ['station_id'])]
#[ORM\Index(name: 'idx_radio_history_played_at', columns: ['played_at'])]
class MusicbotRadioHistory
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
    private ?MusicbotInstance $instance;

    #[ORM\ManyToOne(targetEntity: MusicbotRadioStation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotRadioStation $station;

    #[ORM\Column]
    private \DateTimeImmutable $playedAt;

    public function __construct(User $customer, MusicbotRadioStation $station, ?MusicbotInstance $instance = null)
    {
        $this->customer = $customer;
        $this->station = $station;
        $this->instance = $instance;
        $this->playedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCustomer(): User { return $this->customer; }
    public function getInstance(): ?MusicbotInstance { return $this->instance; }
    public function getStation(): MusicbotRadioStation { return $this->station; }
    public function getPlayedAt(): \DateTimeImmutable { return $this->playedAt; }
}
