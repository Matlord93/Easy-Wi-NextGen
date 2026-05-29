<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\GameTemplateSeedCatalog;
use PHPUnit\Framework\TestCase;

final class GameTemplateSeedCatalogTest extends TestCase
{
    private GameTemplateSeedCatalog $catalog;

    /** @var array<string, array<string, mixed>> */
    private array $templates;

    protected function setUp(): void
    {
        $this->catalog = new GameTemplateSeedCatalog();
        $this->templates = [];
        foreach ($this->catalog->listTemplates() as $tpl) {
            $key = $tpl['game_key'] ?? '';
            if ($key !== '') {
                $this->templates[$key] = $tpl;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Vanilla
    // -------------------------------------------------------------------------

    public function testVanillaStartParamsContainsPortGame(): void
    {
        $params = (string) ($this->templates['minecraft_vanilla_all']['start_params'] ?? '');
        self::assertStringContainsString('{{PORT_GAME}}', $params);
        self::assertStringNotContainsString('{{SERVER_PORT}}', $params);
    }

    public function testVanillaStartParamsContainsSetPropertyServerPort(): void
    {
        $params = (string) ($this->templates['minecraft_vanilla_all']['start_params'] ?? '');
        self::assertStringContainsString('set_property server.properties server-port', $params);
    }

    public function testVanillaStartParamsSetsMotdViaSetProperty(): void
    {
        $params = (string) ($this->templates['minecraft_vanilla_all']['start_params'] ?? '');
        self::assertStringContainsString('set_property server.properties motd', $params);
    }

    public function testVanillaConfigFilesServerPropertiesUsesPortGame(): void
    {
        $configFiles = (array) ($this->templates['minecraft_vanilla_all']['config_files'] ?? []);
        $serverProps = $this->findConfigFile($configFiles, 'server.properties');
        self::assertNotNull($serverProps, 'server.properties config file not found for Vanilla');
        self::assertStringContainsString('server-port={{PORT_GAME}}', (string) ($serverProps['contents'] ?? ''));
        self::assertStringNotContainsString('server-port={{SERVER_PORT}}', (string) ($serverProps['contents'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // Paper
    // -------------------------------------------------------------------------

    public function testPaperStartParamsContainsPortGame(): void
    {
        $params = (string) ($this->templates['minecraft_paper_all']['start_params'] ?? '');
        self::assertStringContainsString('{{PORT_GAME}}', $params);
        self::assertStringNotContainsString('{{SERVER_PORT}}', $params);
    }

    public function testPaperStartParamsContainsSetPropertyServerPort(): void
    {
        $params = (string) ($this->templates['minecraft_paper_all']['start_params'] ?? '');
        self::assertStringContainsString('set_property server.properties server-port', $params);
    }

    public function testPaperStartParamsSetsMotdViaSetProperty(): void
    {
        $params = (string) ($this->templates['minecraft_paper_all']['start_params'] ?? '');
        self::assertStringContainsString('set_property server.properties motd', $params);
    }

    public function testPaperConfigFilesServerPropertiesUsesPortGame(): void
    {
        $configFiles = (array) ($this->templates['minecraft_paper_all']['config_files'] ?? []);
        $serverProps = $this->findConfigFile($configFiles, 'server.properties');
        self::assertNotNull($serverProps, 'server.properties config file not found for Paper');
        self::assertStringContainsString('server-port={{PORT_GAME}}', (string) ($serverProps['contents'] ?? ''));
        self::assertStringNotContainsString('server-port={{SERVER_PORT}}', (string) ($serverProps['contents'] ?? ''));
    }

    // -------------------------------------------------------------------------
    // Bedrock
    // -------------------------------------------------------------------------

    public function testBedrockStartParamsContainsSetPropertyServerPort(): void
    {
        $params = (string) ($this->templates['minecraft_bedrock']['start_params'] ?? '');
        self::assertStringContainsString('set_property server.properties server-port', $params);
    }

    public function testBedrockStartParamsContainsSetPropertyServerPortV6(): void
    {
        $params = (string) ($this->templates['minecraft_bedrock']['start_params'] ?? '');
        self::assertStringContainsString('set_property server.properties server-portv6', $params);
    }

    public function testBedrockStartParamsContainsPortGame(): void
    {
        $params = (string) ($this->templates['minecraft_bedrock']['start_params'] ?? '');
        self::assertStringContainsString('{{PORT_GAME}}', $params);
    }

    public function testBedrockStartParamsDoesNotContainHardcoded19133(): void
    {
        $params = (string) ($this->templates['minecraft_bedrock']['start_params'] ?? '');
        self::assertStringNotContainsString('19133', $params);
    }

    public function testBedrockStartParamsSetsServerNameViaSetProperty(): void
    {
        $params = (string) ($this->templates['minecraft_bedrock']['start_params'] ?? '');
        self::assertStringContainsString('set_property server.properties server-name', $params);
    }

    public function testBedrockConfigFilesServerPropertiesUsesPortGameForPortV6(): void
    {
        $configFiles = (array) ($this->templates['minecraft_bedrock']['config_files'] ?? []);
        $serverProps = $this->findConfigFile($configFiles, 'server.properties');
        self::assertNotNull($serverProps, 'server.properties config file not found for Bedrock');
        self::assertStringContainsString('server-portv6={{PORT_GAME}}', (string) ($serverProps['contents'] ?? ''));
    }

    public function testBedrockConfigFilesServerPropertiesContainsServerPassword(): void
    {
        $configFiles = (array) ($this->templates['minecraft_bedrock']['config_files'] ?? []);
        $serverProps = $this->findConfigFile($configFiles, 'server.properties');
        self::assertNotNull($serverProps, 'server.properties config file not found for Bedrock');
        self::assertStringContainsString('server-password={{SERVER_PASSWORD}}', (string) ($serverProps['contents'] ?? ''));
    }

    public function testBedrockEnvVarsIncludesMaxPlayers(): void
    {
        $envVars = (array) ($this->templates['minecraft_bedrock']['env_vars'] ?? []);
        $keys = array_column($envVars, 'key');
        self::assertContains('MAX_PLAYERS', $keys, 'Bedrock env_vars should include MAX_PLAYERS');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, mixed> $configFiles
     * @return array<string, mixed>|null
     */
    private function findConfigFile(array $configFiles, string $path): ?array
    {
        foreach ($configFiles as $file) {
            if (is_array($file) && ($file['path'] ?? '') === $path) {
                return $file;
            }
        }

        return null;
    }
}
