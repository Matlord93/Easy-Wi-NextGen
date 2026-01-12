<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConsentType;
use App\Repository\ConsentLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConsentLogRepository::class)]
#[ORM\Table(name: 'consent_logs')]
class ConsentLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(enumType: ConsentType::class)]
    private ConsentType $type;

    #[ORM\Column]
    private \DateTimeImmutable $acceptedAt;

    #[ORM\Column(length: 64)]
    private string $ip;

    #[ORM\Column(length: 255)]
    private string $userAgent;

    #[ORM\Column(length: 120)]
    private string $version;

    public function __construct(User $user, ConsentType $type, string $ip, string $userAgent, string $version)
    {
        $this->user = $user;
        $this->type = $type;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
        $this->version = $version;
        $this->acceptedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): ConsentType
    {
        return $this->type;
    }

    public function getAcceptedAt(): \DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getVersion(): string
    {
        return $this->version;
    }
}
