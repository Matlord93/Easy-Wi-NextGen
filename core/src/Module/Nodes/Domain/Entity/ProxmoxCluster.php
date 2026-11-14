<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use App\Module\Core\Application\SecretsCrypto;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Doctrine hydrates and proxies this entity; Twig also calls its public accessors.
 *
 * @psalm-api
 */
#[ORM\Entity]
#[ORM\Table(name: 'proxmox_clusters')]
class ProxmoxCluster
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 120)]
    private string $name;
    #[ORM\Column(length: 255)]
    private string $baseUrl;
    #[ORM\Column(length: 190)]
    private string $tokenId;
    #[ORM\Column(type: 'text')]
    private string $encryptedToken;
    #[ORM\Column]
    private bool $enabled = true;
    #[ORM\Column(options: ['default' => true])]
    private bool $verifyTls = true;
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, ProxmoxConnection $connection, SecretsCrypto $crypto)
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->configure($name, $connection->baseUrl, $connection->tokenId, $connection->tokenSecret, $crypto, $connection->verifyTls);
    }

    public function configure(string $name, string $baseUrl, string $tokenId, #[\SensitiveParameter] string $secret, SecretsCrypto $crypto, ?bool $verifyTls = null): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('A cluster name of 1–120 characters is required.');
        }
        if ('' === $secret && ($this->baseUrl !== rtrim(trim($baseUrl), '/') || $this->tokenId !== trim($tokenId))) {
            throw new \InvalidArgumentException('Enter the token secret again when changing the endpoint or token identity.');
        }
        $connection = new ProxmoxConnection(rtrim(trim($baseUrl), '/'), trim($tokenId), '' === $secret ? $crypto->decrypt($this->encryptedToken) : $secret, $verifyTls ?? $this->verifyTls);
        $this->name = $name;
        $this->baseUrl = $connection->baseUrl;
        $this->tokenId = $connection->tokenId;
        $this->verifyTls = $connection->verifyTls;
        if ('' !== $secret) {
            $this->encryptedToken = $crypto->encrypt($secret);
        }
    }

    public function connection(SecretsCrypto $crypto): ProxmoxConnection
    {
        if (!$this->enabled) {
            throw new \RuntimeException('This Proxmox connection is disabled.');
        }

        return new ProxmoxConnection($this->baseUrl, $this->tokenId, $crypto->decrypt($this->encryptedToken), $this->verifyTls);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getTokenId(): string
    {
        return $this->tokenId;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isTlsVerificationEnabled(): bool
    {
        return $this->verifyTls;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
