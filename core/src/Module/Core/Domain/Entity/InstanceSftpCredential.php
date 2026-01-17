<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\InstanceSftpCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InstanceSftpCredentialRepository::class)]
#[ORM\Table(name: 'instance_sftp_credentials')]
class InstanceSftpCredential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Instance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Instance $instance;

    #[ORM\Column(length: 190)]
    private string $username;

    #[ORM\Column(type: 'json')]
    private array $encryptedPassword;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedPassword
     */
    public function __construct(Instance $instance, string $username, array $encryptedPassword)
    {
        $this->instance = $instance;
        $this->username = $username;
        $this->encryptedPassword = $encryptedPassword;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstance(): Instance
    {
        return $this->instance;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}
     */
    public function getEncryptedPassword(): array
    {
        return $this->encryptedPassword;
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedPassword
     */
    public function setEncryptedPassword(array $encryptedPassword): void
    {
        $this->encryptedPassword = $encryptedPassword;
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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
