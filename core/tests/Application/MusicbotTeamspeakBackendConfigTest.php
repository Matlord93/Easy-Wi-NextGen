<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotTeamspeakBackendConfig;
use App\Module\Musicbot\Domain\Enum\MusicbotTeamspeakBackendStatus;
use PHPUnit\Framework\TestCase;

final class MusicbotTeamspeakBackendConfigTest extends TestCase
{
    public function testAgentPayloadContainsNoSecretsAndIncludesExpectedChecksum(): void
    {
        $config = (new \ReflectionClass(MusicbotTeamspeakBackendConfig::class))->newInstanceWithoutConstructor();
        $this->set($config, 'backendType', 'client_library');
        $this->set($config, 'backendPath', '/usr/local/bin/easywi-teamspeak-client');
        $this->set($config, 'binaryPath', '/usr/local/bin/easywi-teamspeak-client');
        $this->set($config, 'libraryPath', '/opt/easywi/musicbot/teamspeak-client/libts3client.so');
        $this->set($config, 'opusLibraryPath', '/opt/easywi/musicbot/teamspeak-client/libopus.so');
        $this->set($config, 'identityPath', null);
        $this->set($config, 'installPath', '/opt/easywi/musicbot/teamspeak-client/');
        $this->set($config, 'version', null);
        $this->set($config, 'checksum', 'abc123');
        $this->set($config, 'autoInstallEnabled', false);
        $this->set($config, 'status', MusicbotTeamspeakBackendStatus::NotConfigured);
        $this->set($config, 'lastError', null);
        $this->set($config, 'lastCheckedAt', null);
        $this->set($config, 'officialClientInstallEnabled', true);
        $this->set($config, 'officialClientVersion', '3.6.2');
        $this->set($config, 'officialClientDownloadUrl', 'https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run');
        $this->set($config, 'officialClientExpectedSha256', 'sha');
        $this->set($config, 'officialClientInstallPath', '/opt/easywi/musicbot/teamspeak-client/official-client/');
        $this->set($config, 'officialClientStatus', 'official_client_not_installed');
        $this->set($config, 'officialClientLastError', null);
        $this->set($config, 'officialClientLastInstalledAt', null);

        $payload = $config->toAgentPayload();

        self::assertSame('abc123', $payload['expected_checksum']);
        self::assertArrayHasKey('backend_path', $payload);
        self::assertArrayHasKey('library_path', $payload);
        self::assertArrayHasKey('opus_library_path', $payload);
        self::assertArrayNotHasKey('server_password', $payload);
        self::assertArrayNotHasKey('channel_password', $payload);

        $officialPayload = $config->toOfficialClientAgentPayload('99');
        self::assertSame('3.6.2', $officialPayload['version']);
        self::assertSame('99', $officialPayload['requested_by']);
        self::assertTrue($officialPayload['accepted_license_confirmation']);
        self::assertArrayNotHasKey('server_password', $officialPayload);
    }

    public function testAgentResultUpdatesReadyAndFailureStatuses(): void
    {
        $config = (new \ReflectionClass(MusicbotTeamspeakBackendConfig::class))->newInstanceWithoutConstructor();
        $this->set($config, 'status', MusicbotTeamspeakBackendStatus::NotConfigured);
        $this->set($config, 'lastError', 'old');
        $this->set($config, 'checksum', null);
        $this->set($config, 'version', null);
        $this->set($config, 'lastCheckedAt', null);
        $this->set($config, 'libraryPath', '');
        $this->set($config, 'opusLibraryPath', null);
        $this->set($config, 'officialClientInstallPath', '/opt/easywi/musicbot/teamspeak-client/official-client/');
        $this->set($config, 'officialClientStatus', 'official_client_not_installed');
        $this->set($config, 'officialClientLastError', null);
        $this->set($config, 'officialClientLastInstalledAt', null);

        $config->applyAgentResult(['status' => 'ready', 'checksum' => 'def456', 'version' => '1.2.3']);
        self::assertSame(MusicbotTeamspeakBackendStatus::Ready, $config->getStatus());
        self::assertSame('def456', $config->getChecksum());
        self::assertSame('1.2.3', $config->getVersion());
        self::assertNull($config->getLastError());

        $config->applyAgentResult(['status' => 'client_backend_required', 'last_error' => 'stub build']);
        self::assertSame(MusicbotTeamspeakBackendStatus::ClientBackendRequired, $config->getStatus());
        self::assertSame('stub build', $config->getLastError());

        $config->applyAgentResult(['status' => 'library_missing', 'last_error' => 'libts3client.so missing']);
        self::assertSame(MusicbotTeamspeakBackendStatus::LibraryMissing, $config->getStatus());

        $config->applyAgentResult(['status' => 'opus_missing', 'last_error' => 'libopus.so missing']);
        self::assertSame(MusicbotTeamspeakBackendStatus::OpusMissing, $config->getStatus());

        $config->applyAgentResult(['status' => 'official_client_installed_library_missing', 'last_error' => 'no libts3client.so']);
        self::assertSame(MusicbotTeamspeakBackendStatus::OfficialClientInstalledLibraryMissing, $config->getStatus());
        self::assertSame('official_client_installed_library_missing', $config->getOfficialClientStatus());
    }

    public function testOfficialClientResultAppliesBackendSuggestions(): void
    {
        $config = (new \ReflectionClass(MusicbotTeamspeakBackendConfig::class))->newInstanceWithoutConstructor();
        $this->set($config, 'status', MusicbotTeamspeakBackendStatus::NotConfigured);
        $this->set($config, 'backendType', 'client_library');
        $this->set($config, 'backendPath', '');
        $this->set($config, 'binaryPath', '');
        $this->set($config, 'checksum', null);
        $this->set($config, 'version', null);
        $this->set($config, 'lastError', null);
        $this->set($config, 'lastCheckedAt', null);
        $this->set($config, 'libraryPath', '');
        $this->set($config, 'opusLibraryPath', null);
        $this->set($config, 'officialClientInstallPath', '/opt/easywi/musicbot/teamspeak-client/official-client/');
        $this->set($config, 'officialClientStatus', 'official_client_not_installed');
        $this->set($config, 'officialClientLastError', null);
        $this->set($config, 'officialClientLastInstalledAt', null);

        $config->applyAgentResult([
            'status' => 'official_client_installed',
            'library_path' => '/opt/easywi/musicbot/teamspeak-client/official-client/libts3client.so',
            'backend_type_suggestion' => 'client_library',
            'backend_path_suggestion' => '/usr/local/bin/easywi-teamspeak-client',
        ]);

        self::assertSame(MusicbotTeamspeakBackendStatus::OfficialClientInstalled, $config->getStatus());
        self::assertSame('/opt/easywi/musicbot/teamspeak-client/official-client/libts3client.so', $config->getLibraryPath());
        self::assertSame('client_library', $config->getBackendType());
        self::assertSame('/usr/local/bin/easywi-teamspeak-client', $config->getBackendPath());
        self::assertSame('/usr/local/bin/easywi-teamspeak-client', $config->getBinaryPath());
    }

    public function testOfficialClientResultDoesNotOverwriteExistingBackendPath(): void
    {
        $config = (new \ReflectionClass(MusicbotTeamspeakBackendConfig::class))->newInstanceWithoutConstructor();
        $this->set($config, 'status', MusicbotTeamspeakBackendStatus::Ready);
        $this->set($config, 'backendType', 'client_library');
        $this->set($config, 'backendPath', '/usr/local/bin/my-custom-client');
        $this->set($config, 'binaryPath', '/usr/local/bin/my-custom-client');
        $this->set($config, 'checksum', null);
        $this->set($config, 'version', null);
        $this->set($config, 'lastError', null);
        $this->set($config, 'lastCheckedAt', null);
        $this->set($config, 'libraryPath', '');
        $this->set($config, 'opusLibraryPath', null);
        $this->set($config, 'officialClientInstallPath', '/opt/easywi/musicbot/teamspeak-client/official-client/');
        $this->set($config, 'officialClientStatus', 'official_client_not_installed');
        $this->set($config, 'officialClientLastError', null);
        $this->set($config, 'officialClientLastInstalledAt', null);

        $config->applyAgentResult([
            'status' => 'official_client_installed',
            'backend_type_suggestion' => 'client_library',
            'backend_path_suggestion' => '/usr/local/bin/easywi-teamspeak-client',
        ]);

        self::assertSame('/usr/local/bin/my-custom-client', $config->getBackendPath(), 'Existing backend_path must not be overwritten by suggestion');
        self::assertSame('/usr/local/bin/my-custom-client', $config->getBinaryPath());
    }

    private function set(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
}
