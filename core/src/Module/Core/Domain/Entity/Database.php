<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Module\Core\Domain\Event\ResourceEventSource;
use App\Module\Core\Domain\Event\ResourceEventSourceTrait;
use App\Repository\DatabaseRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DatabaseRepository::class)]
#[ORM\Table(name: '`databases`')]
class Database implements ResourceEventSource
{
    use ResourceEventSourceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $customer;

    #[ORM\Column(length: 30)]
    private string $engine;

    #[ORM\Column(length: 255)]
    private string $host;

    #[ORM\Column]
    private int $port;

    #[ORM\Column(length: 190)]
    private string $name;

    #[ORM\Column(length: 190)]
    private string $username;

    #[ORM\ManyToOne(targetEntity: DatabaseNode::class)]
    #[ORM\JoinColumn(name: 'database_node_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?DatabaseNode $node = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $encryptedPassword = null;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = 'pending';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lastErrorCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rotatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedPassword
     */
    public function __construct(
        User $customer,
        string $engine,
        string $host,
        int $port,
        string $name,
        string $username,
        ?array $encryptedPassword = null,
        ?DatabaseNode $node = null,
    ) {
        $this->customer = $customer;
        $this->engine = $engine;
        $this->host = $host;
        $this->port = $port;
        $this->name = $name;
        $this->username = $username;
        $this->encryptedPassword = $encryptedPassword;
        $this->node = $node;
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

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getNode(): ?DatabaseNode
    {
        return $this->node;
    }

    /**
     * @return array{key_id: string, nonce: string, ciphertext: string}
     */
    public function getEncryptedPassword(): ?array
    {
        return $this->encryptedPassword;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }


    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
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
        $this->lastErrorCode = $code;
        $this->lastErrorMessage = $message;
        $this->touch();
    }

    public function getRotatedAt(): ?\DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function markRotated(): void
    {
        $this->rotatedAt = new \DateTimeImmutable();
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
    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @param array{key_id: string, nonce: string, ciphertext: string} $encryptedPassword
     */
    public function setEncryptedPassword(?array $encryptedPassword): void
    {
        $this->encryptedPassword = $encryptedPassword;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
