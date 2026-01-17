<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\Ts6NodeRepository;
use App\Module\Core\Application\SecretsCrypto;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: Ts6NodeRepository::class)]
#[ORM\Table(name: 'ts6_nodes')]
class Ts6Node
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

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $osType = null;

    #[ORM\Column(length: 255)]
    private string $downloadUrl;

    #[ORM\Column(length: 255)]
    private string $installPath;

    #[ORM\Column(length: 120)]
    private string $instanceName;

    #[ORM\Column(length: 120)]
    private string $serviceName;

    #[ORM\Column(length: 64)]
    private string $queryBindIp = '127.0.0.1';

    #[ORM\Column]
    private int $queryHttpsPort = 10443;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $installedVersion = null;

    #[ORM\Column(length: 32)]
    private string $installStatus = 'not_installed';

    #[ORM\Column]
    private bool $running = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 64)]
    private string $adminUsername = 'serveradmin';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminPasswordEncrypted = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $adminPasswordShownOnceAt = null;

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
        string $instanceName,
        string $serviceName,
    ) {
        $this->name = $name;
        $this->agentBaseUrl = $agentBaseUrl;
        $this->agentApiTokenEncrypted = $agentApiTokenEncrypted;
        $this->downloadUrl = $downloadUrl;
        $this->installPath = $installPath;
        $this->instanceName = $instanceName;
        $this->serviceName = $serviceName;
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

    public function getOsType(): ?string
    {
        return $this->osType;
    }

    public function setOsType(?string $osType): void
    {
        $this->osType = $osType !== null ? strtolower($osType) : null;
        $this->touch();
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

    public function getInstanceName(): string
    {
        return $this->instanceName;
    }

    public function setInstanceName(string $instanceName): void
    {
        $this->instanceName = $instanceName;
        $this->touch();
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    public function setServiceName(string $serviceName): void
    {
        $this->serviceName = $serviceName;
        $this->touch();
    }

    public function getQueryBindIp(): string
    {
        return $this->queryBindIp;
    }

    public function setQueryBindIp(string $queryBindIp): void
    {
        $this->queryBindIp = $queryBindIp;
        $this->touch();
    }

    public function getQueryHttpsPort(): int
    {
        return $this->queryHttpsPort;
    }

    public function setQueryHttpsPort(int $queryHttpsPort): void
    {
        $this->queryHttpsPort = max(1, $queryHttpsPort);
        $this->touch();
    }

    public function getInstalledVersion(): ?string
    {
        return $this->installedVersion;
    }

    public function setInstalledVersion(?string $installedVersion): void
    {
        $this->installedVersion = $installedVersion;
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

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function setRunning(bool $running): void
    {
        $this->running = $running;
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

    public function getAdminUsername(): string
    {
        return $this->adminUsername;
    }

    public function setAdminUsername(string $adminUsername): void
    {
        $this->adminUsername = $adminUsername;
        $this->touch();
    }

    public function getAdminPasswordEncrypted(): ?string
    {
        return $this->adminPasswordEncrypted;
    }

    public function setAdminPassword(?string $password, SecretsCrypto $crypto): void
    {
        $this->adminPasswordEncrypted = $password === null ? null : $crypto->encrypt($password);
        $this->touch();
    }

    public function getAdminPassword(?SecretsCrypto $crypto): ?string
    {
        if ($crypto === null || $this->adminPasswordEncrypted === null) {
            return null;
        }

        return $crypto->decrypt($this->adminPasswordEncrypted);
    }

    public function getAdminPasswordShownOnceAt(): ?\DateTimeImmutable
    {
        return $this->adminPasswordShownOnceAt;
    }

    public function markAdminPasswordShown(): void
    {
        if ($this->adminPasswordShownOnceAt === null) {
            $this->adminPasswordShownOnceAt = new \DateTimeImmutable();
            $this->touch();
        }
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
