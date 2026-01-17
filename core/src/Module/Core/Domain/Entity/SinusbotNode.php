<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\SinusbotNodeRepository;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SinusbotNodeRepository::class)]
#[ORM\Table(name: 'sinusbot_nodes')]
class SinusbotNode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $agentBaseUrl;

    #[ORM\Column(type: 'text')]
    private string $agentApiTokenEncrypted;

    #[ORM\Column(length: 255)]
    private string $downloadUrl;

    #[ORM\Column(length: 255)]
    private string $installPath;

    #[ORM\Column(length: 255)]
    private string $instanceRoot;

    #[ORM\Column(length: 64)]
    private string $webBindIp = '127.0.0.1';

    #[ORM\Column]
    private int $webPortBase = 8087;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $installedVersion = null;

    #[ORM\Column(length: 32)]
    private string $installStatus = 'not_installed';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $adminUsername = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminPasswordEncrypted = null;

    #[ORM\Column]
    private bool $ts3ClientInstalled = false;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ts3ClientVersion = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ts3ClientPath = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        string $agentBaseUrl,
        string $agentApiTokenEncrypted,
        string $downloadUrl,
        string $installPath,
        string $instanceRoot,
    ) {
        $this->name = $name;
        $this->agentBaseUrl = rtrim($agentBaseUrl, '/');
        $this->agentApiTokenEncrypted = $agentApiTokenEncrypted;
        $this->downloadUrl = $downloadUrl;
        $this->installPath = $installPath;
        $this->instanceRoot = $instanceRoot;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getAgentBaseUrl(): string
    {
        return $this->agentBaseUrl;
    }

    public function setAgentBaseUrl(string $agentBaseUrl): void
    {
        $this->agentBaseUrl = rtrim($agentBaseUrl, '/');
        $this->touch();
    }

    public function getAgentApiTokenEncrypted(): string
    {
        return $this->agentApiTokenEncrypted;
    }

    public function setAgentApiToken(string $token, SecretsCrypto $crypto): void
    {
        $this->agentApiTokenEncrypted = $crypto->encrypt($token);
        $this->touch();
    }

    public function getAgentApiToken(SecretsCrypto $crypto): string
    {
        return $crypto->decrypt($this->agentApiTokenEncrypted);
    }

    public function getDownloadUrl(): string
    {
        return $this->downloadUrl;
    }

    public function setDownloadUrl(string $downloadUrl): void
    {
        $this->downloadUrl = $downloadUrl;
        $this->touch();
    }

    public function getInstallPath(): string
    {
        return $this->installPath;
    }

    public function setInstallPath(string $installPath): void
    {
        $this->installPath = $installPath;
        $this->touch();
    }

    public function getInstanceRoot(): string
    {
        return $this->instanceRoot;
    }

    public function setInstanceRoot(string $instanceRoot): void
    {
        $this->instanceRoot = $instanceRoot;
        $this->touch();
    }

    public function getWebBindIp(): string
    {
        return $this->webBindIp;
    }

    public function setWebBindIp(string $webBindIp): void
    {
        $this->webBindIp = $webBindIp;
        $this->touch();
    }

    public function getWebPortBase(): int
    {
        return $this->webPortBase;
    }

    public function setWebPortBase(int $webPortBase): void
    {
        $this->webPortBase = $webPortBase;
        $this->touch();
    }

    public function getInstalledVersion(): ?string
    {
        return $this->installedVersion;
    }

    public function setInstalledVersion(?string $installedVersion): void
    {
        $this->installedVersion = $installedVersion !== '' ? $installedVersion : null;
        $this->touch();
    }

    public function getInstallStatus(): string
    {
        return $this->installStatus;
    }

    public function setInstallStatus(string $installStatus): void
    {
        $this->installStatus = $installStatus;
        $this->touch();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError;
        $this->touch();
    }

    public function getAdminUsername(): ?string
    {
        return $this->adminUsername;
    }

    public function setAdminUsername(?string $adminUsername): void
    {
        $this->adminUsername = $adminUsername !== '' ? $adminUsername : null;
        $this->touch();
    }

    public function setAdminPassword(?string $password, SecretsCrypto $crypto): void
    {
        $this->adminPasswordEncrypted = $password !== null ? $crypto->encrypt($password) : null;
        $this->touch();
    }

    public function getAdminPassword(?SecretsCrypto $crypto): ?string
    {
        if ($crypto === null || $this->adminPasswordEncrypted === null) {
            return null;
        }

        return $crypto->decrypt($this->adminPasswordEncrypted);
    }

    public function isTs3ClientInstalled(): bool
    {
        return $this->ts3ClientInstalled;
    }

    public function setTs3ClientInstalled(bool $ts3ClientInstalled): void
    {
        $this->ts3ClientInstalled = $ts3ClientInstalled;
        $this->touch();
    }

    public function getTs3ClientVersion(): ?string
    {
        return $this->ts3ClientVersion;
    }

    public function setTs3ClientVersion(?string $ts3ClientVersion): void
    {
        $this->ts3ClientVersion = $ts3ClientVersion !== '' ? $ts3ClientVersion : null;
        $this->touch();
    }

    public function getTs3ClientPath(): ?string
    {
        return $this->ts3ClientPath;
    }

    public function setTs3ClientPath(?string $ts3ClientPath): void
    {
        $this->ts3ClientPath = $ts3ClientPath !== '' ? $ts3ClientPath : null;
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
