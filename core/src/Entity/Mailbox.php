<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MailboxRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailboxRepository::class)]
#[ORM\Table(name: 'mailboxes')]
class Mailbox
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\ManyToOne(targetEntity: Domain::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Domain $domain;

    #[ORM\Column(length: 190)]
    private string $localPart;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: 'json')]
    private array $secretPayload;

    #[ORM\Column]
    private int $quota;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $secretPayload
     */
    public function __construct(Domain $domain, string $localPart, string $passwordHash, array $secretPayload, int $quota, bool $enabled = true)
    {
        $this->domain = $domain;
        $this->customer = $domain->getCustomer();
        $this->localPart = $localPart;
        $this->address = sprintf('%s@%s', $localPart, $domain->getName());
        $this->passwordHash = $passwordHash;
        $this->secretPayload = $secretPayload;
        $this->quota = $quota;
        $this->enabled = $enabled;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getDomain(): Domain
    {
        return $this->domain;
    }

    public function getLocalPart(): string
    {
        return $this->localPart;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}
     */
    public function getSecretPayload(): array
    {
        return $this->secretPayload;
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setQuota(int $quota): void
    {
        $this->quota = $quota;
        $this->touch();
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $secretPayload
     */
    public function setPassword(string $passwordHash, array $secretPayload): void
    {
        $this->passwordHash = $passwordHash;
        $this->secretPayload = $secretPayload;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
