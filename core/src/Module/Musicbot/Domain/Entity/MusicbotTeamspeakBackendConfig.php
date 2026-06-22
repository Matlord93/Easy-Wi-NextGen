<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Musicbot\Domain\Enum\MusicbotTeamspeakBackendStatus;
use App\Repository\MusicbotTeamspeakBackendConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MusicbotTeamspeakBackendConfigRepository::class)]
#[ORM\Table(name: 'musicbot_teamspeak_backend_configs')]
#[ORM\Index(name: 'idx_musicbot_ts_backend_node', columns: ['node_id'])]
class MusicbotTeamspeakBackendConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Agent::class)]
    #[ORM\JoinColumn(name: 'node_id', nullable: false, onDelete: 'CASCADE')]
    private Agent $node;

    #[ORM\Column(length: 32)]
    private string $backendType = 'client_library';

    #[ORM\Column(length: 1024)]
    private string $backendPath = '';

    #[ORM\Column(length: 1024)]
    private string $libraryPath = '';

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $opusLibraryPath = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $identityPath = null;

    #[ORM\Column(length: 1024)]
    private string $installPath = '/opt/easywi/musicbot/teamspeak-client/';

    #[ORM\Column(length: 1024)]
    private string $binaryPath = '/usr/local/bin/easywi-teamspeak-client';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $checksum = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $autoInstallEnabled = false;

    #[ORM\Column(enumType: MusicbotTeamspeakBackendStatus::class)]
    private MusicbotTeamspeakBackendStatus $status = MusicbotTeamspeakBackendStatus::NotConfigured;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $officialClientInstallEnabled = false;

    #[ORM\Column(length: 32, options: ['default' => '3.6.2'])]
    private string $officialClientVersion = '3.6.2';

    #[ORM\Column(length: 1024, options: ['default' => 'https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run'])]
    private string $officialClientDownloadUrl = 'https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $officialClientExpectedSha256 = null;

    #[ORM\Column(length: 1024, options: ['default' => '/opt/easywi/musicbot/teamspeak-client/official-client/'])]
    private string $officialClientInstallPath = '/opt/easywi/musicbot/teamspeak-client/official-client/';

    #[ORM\Column(length: 64, options: ['default' => 'official_client_not_installed'])]
    private string $officialClientStatus = 'official_client_not_installed';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $officialClientLastError = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $officialClientLastInstalledAt = null;

    public function __construct(Agent $node)
    {
        $this->node = $node;
    }

    public function getId(): ?int { return $this->id; }
    public function getNode(): Agent { return $this->node; }
    public function setNode(Agent $node): void { $this->node = $node; }
    public function getBackendType(): string { return $this->backendType; }
    public function setBackendType(string $backendType): void { $this->backendType = $backendType; }
    public function getBackendPath(): string { return $this->backendPath; }
    public function setBackendPath(string $backendPath): void { $this->backendPath = $backendPath; }
    public function getLibraryPath(): string { return $this->libraryPath; }
    public function setLibraryPath(string $libraryPath): void { $this->libraryPath = $libraryPath; }
    public function getOpusLibraryPath(): ?string { return $this->opusLibraryPath; }
    public function setOpusLibraryPath(?string $opusLibraryPath): void { $this->opusLibraryPath = $opusLibraryPath; }
    public function getIdentityPath(): ?string { return $this->identityPath; }
    public function setIdentityPath(?string $identityPath): void { $this->identityPath = $identityPath; }
    public function getInstallPath(): string { return $this->installPath; }
    public function setInstallPath(string $installPath): void { $this->installPath = $installPath; }
    public function getBinaryPath(): string { return $this->binaryPath; }
    public function setBinaryPath(string $binaryPath): void { $this->binaryPath = $binaryPath; }
    public function getVersion(): ?string { return $this->version; }
    public function setVersion(?string $version): void { $this->version = $version; }
    public function getChecksum(): ?string { return $this->checksum; }
    public function setChecksum(?string $checksum): void { $this->checksum = $checksum; }
    public function isAutoInstallEnabled(): bool { return $this->autoInstallEnabled; }
    public function setAutoInstallEnabled(bool $autoInstallEnabled): void { $this->autoInstallEnabled = $autoInstallEnabled; }
    public function getStatus(): MusicbotTeamspeakBackendStatus { return $this->status; }
    public function setStatus(MusicbotTeamspeakBackendStatus $status): void { $this->status = $status; }
    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $lastError): void { $this->lastError = $lastError; }
    public function getLastCheckedAt(): ?\DateTimeImmutable { return $this->lastCheckedAt; }
    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): void { $this->lastCheckedAt = $lastCheckedAt; }
    public function isOfficialClientInstallEnabled(): bool { return $this->officialClientInstallEnabled; }
    public function setOfficialClientInstallEnabled(bool $officialClientInstallEnabled): void { $this->officialClientInstallEnabled = $officialClientInstallEnabled; }
    public function getOfficialClientVersion(): string { return $this->officialClientVersion; }
    public function setOfficialClientVersion(string $officialClientVersion): void { $this->officialClientVersion = $officialClientVersion; }
    public function getOfficialClientDownloadUrl(): string { return $this->officialClientDownloadUrl; }
    public function setOfficialClientDownloadUrl(string $officialClientDownloadUrl): void { $this->officialClientDownloadUrl = $officialClientDownloadUrl; }
    public function getOfficialClientExpectedSha256(): ?string { return $this->officialClientExpectedSha256; }
    public function setOfficialClientExpectedSha256(?string $officialClientExpectedSha256): void { $this->officialClientExpectedSha256 = $officialClientExpectedSha256; }
    public function getOfficialClientInstallPath(): string { return $this->officialClientInstallPath; }
    public function setOfficialClientInstallPath(string $officialClientInstallPath): void { $this->officialClientInstallPath = $officialClientInstallPath; }
    public function getOfficialClientStatus(): string { return $this->officialClientStatus; }
    public function setOfficialClientStatus(string $officialClientStatus): void { $this->officialClientStatus = $officialClientStatus; }
    public function getOfficialClientLastError(): ?string { return $this->officialClientLastError; }
    public function setOfficialClientLastError(?string $officialClientLastError): void { $this->officialClientLastError = $officialClientLastError; }
    public function getOfficialClientLastInstalledAt(): ?\DateTimeImmutable { return $this->officialClientLastInstalledAt; }
    public function setOfficialClientLastInstalledAt(?\DateTimeImmutable $officialClientLastInstalledAt): void { $this->officialClientLastInstalledAt = $officialClientLastInstalledAt; }

    /** @param array<string, mixed> $payload */
    public function applyAgentResult(array $payload): void
    {
        $status = MusicbotTeamspeakBackendStatus::tryFrom((string) ($payload['status'] ?? '')) ?? MusicbotTeamspeakBackendStatus::Failed;
        $this->status = $status;
        if (is_string($payload['checksum'] ?? null) && $payload['checksum'] !== '') {
            $this->checksum = $payload['checksum'];
        }
        if (is_string($payload['version'] ?? null) && $payload['version'] !== '') {
            $this->version = $payload['version'];
        }
        if (is_string($payload['library_path'] ?? null) && $payload['library_path'] !== '') {
            $this->libraryPath = $payload['library_path'];
        }
        if (is_string($payload['opus_library_path'] ?? null) && $payload['opus_library_path'] !== '') {
            $this->opusLibraryPath = $payload['opus_library_path'];
        }
        if (is_string($payload['official_client_install_path'] ?? null) && $payload['official_client_install_path'] !== '') {
            $this->officialClientInstallPath = $payload['official_client_install_path'];
        }
        if (is_string($payload['official_client_last_installed_at'] ?? null) && $payload['official_client_last_installed_at'] !== '') {
            $this->officialClientLastInstalledAt = new \DateTimeImmutable($payload['official_client_last_installed_at']);
        }
        if (is_string($payload['backend_type_suggestion'] ?? null) && $payload['backend_type_suggestion'] !== '' && in_array($payload['backend_type_suggestion'], ['client_library', 'native_sdk'], true)) {
            $this->backendType = $payload['backend_type_suggestion'];
        }
        if (is_string($payload['backend_path_suggestion'] ?? null) && $payload['backend_path_suggestion'] !== '' && trim($this->backendPath) === '') {
            $this->backendPath = $payload['backend_path_suggestion'];
            if (trim($this->binaryPath) === '') {
                $this->binaryPath = $payload['backend_path_suggestion'];
            }
        }
        if (str_starts_with($status->value, 'official_client_')) {
            $this->officialClientStatus = $status->value;
            $this->officialClientLastError = is_string($payload['last_error'] ?? null) && $payload['last_error'] !== '' ? $payload['last_error'] : null;
        }
        $this->lastError = is_string($payload['last_error'] ?? null) && $payload['last_error'] !== '' ? $payload['last_error'] : null;
        $this->lastCheckedAt = new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    public function toAgentPayload(): array
    {
        return [
            'backend_type' => $this->backendType,
            'backend_path' => $this->backendPath,
            'library_path' => $this->libraryPath,
            'opus_library_path' => $this->opusLibraryPath ?? '',
            'identity_path' => $this->identityPath ?? '',
            'install_path' => $this->installPath,
            'binary_path' => $this->binaryPath,
            'version' => $this->version ?? '',
            'checksum' => $this->checksum ?? '',
            'expected_checksum' => $this->checksum ?? '',
            'auto_install_enabled' => $this->autoInstallEnabled,
        ];
    }

    /** @return array<string, mixed> */
    public function toOfficialClientAgentPayload(string $requestedBy): array
    {
        return [
            'version' => $this->officialClientVersion,
            'download_url' => $this->officialClientDownloadUrl,
            'expected_sha256' => $this->officialClientExpectedSha256 ?? '',
            'install_path' => $this->officialClientInstallPath,
            'requested_by' => $requestedBy,
            'accepted_license_confirmation' => true,
        ];
    }
}
