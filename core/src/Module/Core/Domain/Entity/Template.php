<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity;

use App\Repository\TemplateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TemplateRepository::class)]
#[ORM\Table(name: 'game_templates')]
class Template
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $gameKey;

    #[ORM\Column(length: 120, name: 'display_name')]
    private string $displayName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text')]
    private string $startParams;

    #[ORM\Column(type: 'json')]
    private array $requiredPorts;

    #[ORM\Column(nullable: true)]
    private ?int $steamAppId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $sniperProfile = null;

    #[ORM\Column(type: 'json')]
    private array $envVars;

    #[ORM\Column(type: 'json')]
    private array $configFiles;

    #[ORM\Column(type: 'json')]
    private array $pluginPaths;

    #[ORM\Column(type: 'json')]
    private array $fastdlSettings;

    #[ORM\Column(type: 'text')]
    private string $installCommand;

    #[ORM\Column(type: 'text')]
    private string $updateCommand;

    #[ORM\Column(type: 'json', name: 'install_resolver')]
    private array $installResolver = [];

    #[ORM\Column(type: 'json')]
    private array $allowedSwitchFlags;

    #[ORM\Column(type: 'json', name: 'requirement_vars')]
    private array $requirementVars = [];

    #[ORM\Column(type: 'json', name: 'requirement_secrets')]
    private array $requirementSecrets = [];

    #[ORM\Column(type: 'json', name: 'supported_os')]
    private array $supportedOs = [];

    #[ORM\Column(type: 'json', name: 'port_profile')]
    private array $portProfile = [];

    #[ORM\Column(type: 'json')]
    private array $requirements = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $gameKey,
        string $displayName,
        ?string $description,
        ?int $steamAppId,
        ?string $sniperProfile,
        array $requiredPorts,
        string $startParams,
        array $envVars,
        array $configFiles,
        array $pluginPaths,
        array $fastdlSettings,
        string $installCommand,
        string $updateCommand,
        array $installResolver,
        array $allowedSwitchFlags,
        array $requirementVars = [],
        array $requirementSecrets = [],
        array $supportedOs = [],
        array $portProfile = [],
        array $requirements = [],
    ) {
        $this->gameKey = $gameKey;
        $this->displayName = $displayName;
        $this->description = $description;
        $this->steamAppId = $steamAppId;
        $this->sniperProfile = $sniperProfile;
        $this->requiredPorts = $requiredPorts;
        $this->startParams = $startParams;
        $this->envVars = $envVars;
        $this->configFiles = $configFiles;
        $this->pluginPaths = $pluginPaths;
        $this->fastdlSettings = $fastdlSettings;
        $this->installCommand = $installCommand;
        $this->updateCommand = $updateCommand;
        $this->installResolver = $installResolver;
        $this->allowedSwitchFlags = $allowedSwitchFlags;
        $this->requirementVars = $requirementVars;
        $this->requirementSecrets = $requirementSecrets;
        $this->supportedOs = $supportedOs;
        $this->portProfile = $portProfile;
        $this->requirements = $requirements;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->displayName;
    }

    public function setName(string $name): void
    {
        $this->setDisplayName($name);
    }

    public function getGameKey(): string
    {
        return $this->gameKey;
    }

    public function setGameKey(string $gameKey): void
    {
        $this->gameKey = $gameKey;
        $this->touch();
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getStartParams(): string
    {
        return $this->startParams;
    }

    public function setStartParams(string $startParams): void
    {
        $this->startParams = $startParams;
        $this->touch();
    }

    public function getRequiredPorts(): array
    {
        return $this->requiredPorts;
    }

    public function setRequiredPorts(array $requiredPorts): void
    {
        $this->requiredPorts = $requiredPorts;
        $this->touch();
    }

    public function getRequiredPortLabels(): array
    {
        return array_map(static function (array $port): string {
            $name = (string) ($port['name'] ?? 'port');
            $protocol = (string) ($port['protocol'] ?? 'udp');

            return sprintf('%s/%s', $name, $protocol);
        }, $this->requiredPorts);
    }

    public function getSteamAppId(): ?int
    {
        return $this->steamAppId;
    }

    public function setSteamAppId(?int $steamAppId): void
    {
        $this->steamAppId = $steamAppId;
        $this->touch();
    }

    public function getSniperProfile(): ?string
    {
        return $this->sniperProfile;
    }

    public function setSniperProfile(?string $sniperProfile): void
    {
        $this->sniperProfile = $sniperProfile;
        $this->touch();
    }

    public function getEnvVars(): array
    {
        return $this->envVars;
    }

    public function setEnvVars(array $envVars): void
    {
        $this->envVars = $envVars;
        $this->touch();
    }

    public function getConfigFiles(): array
    {
        return $this->configFiles;
    }

    public function setConfigFiles(array $configFiles): void
    {
        $this->configFiles = $configFiles;
        $this->touch();
    }

    public function getPluginPaths(): array
    {
        return $this->pluginPaths;
    }

    public function setPluginPaths(array $pluginPaths): void
    {
        $this->pluginPaths = $pluginPaths;
        $this->touch();
    }

    public function getFastdlSettings(): array
    {
        return $this->fastdlSettings;
    }

    public function setFastdlSettings(array $fastdlSettings): void
    {
        $this->fastdlSettings = $fastdlSettings;
        $this->touch();
    }

    public function getInstallCommand(): string
    {
        return $this->installCommand;
    }

    public function setInstallCommand(string $installCommand): void
    {
        $this->installCommand = $installCommand;
        $this->touch();
    }

    public function getUpdateCommand(): string
    {
        return $this->updateCommand;
    }

    public function getInstallResolver(): array
    {
        return $this->installResolver;
    }

    public function setInstallResolver(array $installResolver): void
    {
        $this->installResolver = $installResolver;
        $this->touch();
    }

    public function setUpdateCommand(string $updateCommand): void
    {
        $this->updateCommand = $updateCommand;
        $this->touch();
    }

    public function getAllowedSwitchFlags(): array
    {
        return $this->allowedSwitchFlags;
    }

    public function setAllowedSwitchFlags(array $allowedSwitchFlags): void
    {
        $this->allowedSwitchFlags = $allowedSwitchFlags;
        $this->touch();
    }

    public function getRequirementVars(): array
    {
        return $this->requirementVars;
    }

    public function setRequirementVars(array $requirementVars): void
    {
        $this->requirementVars = $requirementVars;
        $this->touch();
    }

    public function getRequirementSecrets(): array
    {
        return $this->requirementSecrets;
    }

    public function setRequirementSecrets(array $requirementSecrets): void
    {
        $this->requirementSecrets = $requirementSecrets;
        $this->touch();
    }

    public function getSupportedOs(): array
    {
        return $this->supportedOs;
    }

    public function setSupportedOs(array $supportedOs): void
    {
        $this->supportedOs = $supportedOs;
        $this->touch();
    }

    public function getPortProfile(): array
    {
        return $this->portProfile;
    }

    public function setPortProfile(array $portProfile): void
    {
        $this->portProfile = $portProfile;
        $this->touch();
    }

    public function getRequirements(): array
    {
        return $this->requirements;
    }

    public function setRequirements(array $requirements): void
    {
        $this->requirements = $requirements;
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
