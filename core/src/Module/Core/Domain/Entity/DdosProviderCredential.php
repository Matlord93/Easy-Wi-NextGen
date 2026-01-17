<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\DdosProviderCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DdosProviderCredentialRepository::class)]
#[ORM\Table(name: 'ddos_provider_credentials')]
#[ORM\UniqueConstraint(name: 'uniq_ddos_provider_customer', columns: ['customer_id', 'provider'])]
class DdosProviderCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(length: 60)]
    private string $provider;

    #[ORM\Column(type: 'json')]
    private array $encryptedApiKey;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedApiKey
     */
    public function __construct(User $customer, string $provider, array $encryptedApiKey)
    {
        $this->customer = $customer;
        $this->provider = $provider;
        $this->encryptedApiKey = $encryptedApiKey;
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

    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}
     */
    public function getEncryptedApiKey(): array
    {
        return $this->encryptedApiKey;
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
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedApiKey
     */
    public function setEncryptedApiKey(array $encryptedApiKey): void
    {
        $this->encryptedApiKey = $encryptedApiKey;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
