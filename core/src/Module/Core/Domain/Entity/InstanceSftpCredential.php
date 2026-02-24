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

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(length: 32, options: ['default' => 'NONE'])]
    private string $backend = 'NONE';

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $host = null;

    #[ORM\Column(nullable: true)]
    private ?int $port = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rootPath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revealedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lastErrorCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastErrorMessage = null;

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
        $this->rotatedAt = $this->createdAt;
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

    public function setUsername(string $username): void
    {
        $normalized = trim($username);
        if ($normalized === '') {
            return;
        }

        $this->username = $normalized;
        $this->touch();
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


    public function getRotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function setRotatedAt(?\DateTimeImmutable $rotatedAt): void
    {
        $this->rotatedAt = $rotatedAt;
        $this->touch();
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
        $this->touch();
    }

    public function getBackend(): string
    {
        return $this->backend;
    }

    public function setBackend(string $backend): void
    {
        $value = strtoupper(trim($backend));
        $this->backend = $value !== '' ? $value : 'NONE';
        $this->touch();
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(?string $host): void
    {
        $normalized = $host !== null ? trim($host) : null;
        $this->host = $normalized !== '' ? $normalized : null;
        $this->touch();
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function setPort(?int $port): void
    {
        $this->port = $port;
        $this->touch();
    }

    public function getRootPath(): ?string
    {
        return $this->rootPath;
    }

    public function setRootPath(?string $rootPath): void
    {
        $normalized = $rootPath !== null ? trim($rootPath) : null;
        $this->rootPath = $normalized !== '' ? $normalized : null;
        $this->touch();
    }

    public function getRevealedAt(): ?\DateTimeImmutable
    {
        return $this->revealedAt;
    }

    public function setRevealedAt(?\DateTimeImmutable $revealedAt): void
    {
        $this->revealedAt = $revealedAt;
        $this->touch();
    }

    public function getLastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function setLastError(?string $code, ?string $message): void
    {
        $this->lastErrorCode = $code !== null ? trim($code) : null;
        $this->lastErrorMessage = $message !== null ? trim($message) : null;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
