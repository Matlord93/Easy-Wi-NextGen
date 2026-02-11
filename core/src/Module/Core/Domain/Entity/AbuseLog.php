<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\AbuseLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AbuseLogRepository::class)]
#[ORM\Table(name: 'abuse_log')]
#[ORM\Index(name: 'idx_abuse_log_type_created', columns: ['type', 'created_at'])]
#[ORM\Index(name: 'idx_abuse_log_ip_created', columns: ['ip_hash', 'created_at'])]
final class AbuseLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $type;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ipHash = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $uaHash = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $emailHash = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $type)
    {
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function setIpHash(?string $ipHash): void
    {
        $this->ipHash = $ipHash;
    }
    public function setUaHash(?string $uaHash): void
    {
        $this->uaHash = $uaHash;
    }
    public function setEmailHash(?string $emailHash): void
    {
        $this->emailHash = $emailHash;
    }
}
