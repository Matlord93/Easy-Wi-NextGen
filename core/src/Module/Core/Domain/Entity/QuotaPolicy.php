<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\QuotaPolicyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuotaPolicyRepository::class)]
#[ORM\Table(name: 'quota_policies')]
class QuotaPolicy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    private string $name;

    #[ORM\Column]
    private int $maxAccounts;

    #[ORM\Column]
    private int $maxDomainQuotaMb;

    #[ORM\Column]
    private int $maxMailboxQuotaMb;

    public function __construct(string $name, int $maxAccounts, int $maxDomainQuotaMb, int $maxMailboxQuotaMb)
    {
        $this->name = trim($name);
        $this->maxAccounts = $maxAccounts;
        $this->maxDomainQuotaMb = $maxDomainQuotaMb;
        $this->maxMailboxQuotaMb = $maxMailboxQuotaMb;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getMaxAccounts(): int
    {
        return $this->maxAccounts;
    }
    public function getMaxDomainQuotaMb(): int
    {
        return $this->maxDomainQuotaMb;
    }
    public function getMaxMailboxQuotaMb(): int
    {
        return $this->maxMailboxQuotaMb;
    }
}
