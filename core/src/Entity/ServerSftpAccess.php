<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServerSftpAccessRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServerSftpAccessRepository::class)]
#[ORM\Table(name: 'server_sftp_access')]
#[ORM\UniqueConstraint(name: 'uniq_server_sftp_access_server', columns: ['server_id'])]
class ServerSftpAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Instance::class)]
    #[ORM\JoinColumn(name: 'server_id', nullable: false, onDelete: 'CASCADE')]
    private Instance $server;

    #[ORM\Column(length: 64)]
    private string $username;

    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordSetAt = null;

    #[ORM\Column(type: 'json')]
    private array $keys = [];

    /**
     * @param list<string> $keys
     */
    public function __construct(Instance $server, string $username, bool $enabled = false, array $keys = [])
    {
        $this->server = $server;
        $this->username = $username;
        $this->enabled = $enabled;
        $this->keys = $keys;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServer(): Instance
    {
        return $this->server;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = $username;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getPasswordSetAt(): ?\DateTimeImmutable
    {
        return $this->passwordSetAt;
    }

    public function setPasswordSetAt(?\DateTimeImmutable $passwordSetAt): void
    {
        $this->passwordSetAt = $passwordSetAt;
    }

    /**
     * @return list<string>
     */
    public function getKeys(): array
    {
        return $this->keys;
    }

    /**
     * @param list<string> $keys
     */
    public function setKeys(array $keys): void
    {
        $this->keys = $keys;
    }
}
