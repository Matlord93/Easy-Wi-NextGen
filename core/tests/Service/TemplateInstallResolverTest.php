<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\JavaBinaryConfig;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Application\TemplateInstallResolver;
use App\Repository\MinecraftVersionCatalogRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class TemplateInstallResolverTest extends TestCase
{
    public function testResolvesVanillaLatestForLinux(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $repository->add(new MinecraftVersionCatalog(
            'vanilla',
            '1.20.3',
            null,
            'https://example.com/vanilla-1.20.3.jar',
            null,
            new \DateTimeImmutable('2024-01-01T00:00:00Z'),
        ));
        $repository->add(new MinecraftVersionCatalog(
            'vanilla',
            '1.20.4',
            null,
            'https://example.com/vanilla-1.20.4.jar',
            null,
            new \DateTimeImmutable('2024-02-01T00:00:00Z'),
        ));

        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'linux');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('curl -L -o server.jar', $command);
        self::assertStringContainsString('https://example.com/vanilla-1.20.4.jar', $command);
    }

    public function testResolvesPaperBuildForWindows(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $repository->add(new MinecraftVersionCatalog(
            'paper',
            '1.20.4',
            '123',
            'https://example.com/paper-1.20.4-123.jar',
            null,
            new \DateTimeImmutable('2024-03-01T00:00:00Z'),
        ));

        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'papermc_paper'], 'windows');
        $instance->setLockedVersion('1.20.4');
        $instance->setLockedBuildId('123');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('powershell -Command', $command);
        self::assertStringContainsString('https://example.com/paper-1.20.4-123.jar', $command);
    }


    public function testBedrockInstallCommandUsesDatabaseUrl(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $repository->add(new MinecraftVersionCatalog('bedrock', '1.21.90.03', null, 'https://example.com/bedrock.zip'));
        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'minecraft_bedrock'], 'linux');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('https://example.com/bedrock.zip', $command);
        self::assertStringContainsString('unzip -o bedrock-server.zip', $command);
    }

    public function testVanillaInstallCommandContainsJavaCheck(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $repository->add(new MinecraftVersionCatalog(
            'vanilla',
            '1.21.4',
            null,
            'https://example.com/vanilla-1.21.4.jar',
            null,
            new \DateTimeImmutable('2024-12-01T00:00:00Z'),
        ));

        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'linux');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('command -v', $command);
        // When catalog entry has no javaVersion, the preamble uses {{JAVA_BIN}} (rendered by agent)
        self::assertStringContainsString('{{JAVA_BIN}}', $command);
        self::assertStringContainsString('Required Java binary', $command);
        self::assertStringContainsString('exit 1', $command);
    }

    public function testVanillaInstallCommandWithCatalogJavaVersionContainsConcreteValue(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $entry = new MinecraftVersionCatalog('vanilla', '1.21.4', null, 'https://example.com/vanilla-1.21.4.jar');
        $entry->setJavaVersion('21');
        $repository->add($entry);

        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'linux');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('java21', $command);
        self::assertStringContainsString('Required Java binary java21 is missing on this node.', $command);
        self::assertStringContainsString('exit 1', $command);
    }

    public function testPaperInstallCommandContainsJavaCheck(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $repository->add(new MinecraftVersionCatalog(
            'paper',
            '1.20.4',
            '123',
            'https://example.com/paper-1.20.4-123.jar',
            null,
            new \DateTimeImmutable('2024-03-01T00:00:00Z'),
        ));

        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'papermc_paper'], 'linux');
        $instance->setLockedVersion('1.20.4');
        $instance->setLockedBuildId('123');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('command -v', $command);
        self::assertStringContainsString('Required Java binary', $command);
        self::assertStringContainsString('exit 1', $command);
    }

    public function testBedrockInstallCommandDoesNotContainJavaCheck(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $repository->add(new MinecraftVersionCatalog('bedrock', '1.21.90.03', null, 'https://example.com/bedrock.zip'));
        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'minecraft_bedrock'], 'linux');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringNotContainsString('Required Java binary', $command);
        self::assertStringNotContainsString('JAVA_BIN', $command);
    }

    public function testWindowsVanillaInstallCommandContainsJavaCheck(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $repository->add(new MinecraftVersionCatalog(
            'vanilla',
            '1.21.4',
            null,
            'https://example.com/vanilla-1.21.4.jar',
        ));

        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'windows');

        $command = $resolver->resolveInstallCommand($instance);

        // Windows uses PowerShell Get-Command check
        self::assertStringContainsString('Get-Command', $command);
        self::assertStringContainsString('Required Java binary', $command);
        self::assertStringContainsString('powershell', $command);
    }

    public function testWindowsInstallScriptContainsWingetAndAdoptium(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $entry = new MinecraftVersionCatalog('vanilla', '1.21.4', null, 'https://example.com/vanilla-1.21.4.jar');
        $entry->setJavaVersion('21');
        $repository->add($entry);

        $config = new JavaBinaryConfig([], true);
        $resolver = $this->buildResolver($repository, $config);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'windows');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('winget', $command);
        self::assertStringContainsString('EclipseAdoptium.Temurin', $command);
        self::assertStringContainsString('api.adoptium.net', $command);
        self::assertStringContainsString('java-setup-done', $command);
        // Should install versions 8, 17, 21 (Java 16 is no longer supported)
        self::assertStringContainsString('Temurin.8.JRE', $command);
        self::assertStringNotContainsString('Temurin.16.JRE', $command);
        self::assertStringContainsString('Temurin.17.JRE', $command);
        self::assertStringContainsString('Temurin.21.JRE', $command);
    }

    public function testCustomJavaBinaryPathAppearsInCheckPreamble(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $entry = new MinecraftVersionCatalog('vanilla', '1.21.4', null, 'https://example.com/vanilla-1.21.4.jar');
        $entry->setJavaVersion('21');
        $repository->add($entry);

        $config = new JavaBinaryConfig(['21' => '/usr/lib/jvm/java-21-openjdk-amd64/bin/java']);
        $resolver = $this->buildResolver($repository, $config);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'linux');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('/usr/lib/jvm/java-21-openjdk-amd64/bin/java', $command);
        self::assertStringContainsString('Required Java binary', $command);
    }

    public function testDefaultVanillaInstallCommandHasNoPackageManagerCalls(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $entry = new MinecraftVersionCatalog('vanilla', '1.21.4', null, 'https://example.com/vanilla-1.21.4.jar');
        $entry->setJavaVersion('21');
        $repository->add($entry);

        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'linux');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringNotContainsString('apt-get', $command);
        self::assertStringNotContainsString('sudo', $command);
        self::assertStringNotContainsString('yum', $command);
        self::assertStringNotContainsString('dnf', $command);
        self::assertStringNotContainsString('java-setup-done', $command);
        self::assertStringContainsString('Required Java binary java21', $command);
        self::assertStringContainsString('exit 1', $command);
    }

    public function testDefaultPaperInstallCommandHasNoPackageManagerCalls(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $entry = new MinecraftVersionCatalog('paper', '1.21.4', '100', 'https://example.com/paper-1.21.4-100.jar');
        $entry->setJavaVersion('21');
        $repository->add($entry);

        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'papermc_paper'], 'linux');
        $instance->setLockedVersion('1.21.4');
        $instance->setLockedBuildId('100');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringNotContainsString('apt-get', $command);
        self::assertStringNotContainsString('sudo', $command);
        self::assertStringNotContainsString('yum', $command);
        self::assertStringNotContainsString('dnf', $command);
        self::assertStringNotContainsString('java-setup-done', $command);
        self::assertStringContainsString('Required Java binary java21', $command);
        self::assertStringContainsString('exit 1', $command);
    }

    public function testAutoInstallJavaInsertsAptGetCommandForAllVersions(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $entry = new MinecraftVersionCatalog('vanilla', '1.21.4', null, 'https://example.com/vanilla-1.21.4.jar');
        $entry->setJavaVersion('21');
        $repository->add($entry);

        $config = new JavaBinaryConfig([], true);
        $resolver = $this->buildResolver($repository, $config);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'linux');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('apt-get', $command);
        self::assertStringContainsString('openjdk-21-jre-headless', $command);
        self::assertStringContainsString('openjdk-17-jre-headless', $command);
        self::assertStringContainsString('openjdk-8-jre-headless', $command);
        // After install attempt, still checks the required binary
        self::assertStringContainsString('Required Java binary java21', $command);
        self::assertStringContainsString('exit 1', $command);
    }

    public function testMinecraft117UsesJava17(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $entry = new MinecraftVersionCatalog('vanilla', '1.17.1', null, 'https://example.com/vanilla-1.17.1.jar');
        $entry->setJavaVersion('17');
        $repository->add($entry);

        $resolver = $this->buildResolver($repository);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'linux');
        $instance->setLockedVersion('1.17.1');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringContainsString('java17', $command);
        self::assertStringNotContainsString('java16', $command);
        self::assertStringNotContainsString('adoptium', $command);
    }

    public function testMissingJavaBinaryProducesExpectedErrorMessageWhenAutoInstallDisabled(): void
    {
        $repository = new InMemoryMinecraftCatalogRepository();
        $entry = new MinecraftVersionCatalog('vanilla', '1.20.4', null, 'https://example.com/vanilla-1.20.4.jar');
        $entry->setJavaVersion('17');
        $repository->add($entry);

        $config = new JavaBinaryConfig([], false);
        $resolver = $this->buildResolver($repository, $config);
        $instance = $this->buildInstance(['type' => 'minecraft_vanilla'], 'linux');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertStringNotContainsString('apt-get', $command);
        self::assertStringContainsString('Required Java binary java17 is missing on this node.', $command);
        self::assertStringContainsString('Install the required Java version', $command);
    }

    public function testPrependsSteamDumpCleanupForLinuxSteamInstallCommands(): void
    {
        $resolver = $this->buildResolver(new InMemoryMinecraftCatalogRepository());
        $instance = $this->buildInstance([], 'linux');
        $instance->getTemplate()->setInstallCommand('steamcmd +app_update 740 validate +quit');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertSame('rm -rf /tmp/dumps /tmp/dumps-* 2>/dev/null || true; steamcmd +app_update 740 validate +quit', $command);
    }

    public function testDoesNotAppendSteamCacheCleanupForWindowsInstallCommands(): void
    {
        $resolver = $this->buildResolver(new InMemoryMinecraftCatalogRepository());
        $instance = $this->buildInstance([], 'windows');
        $instance->getTemplate()->setInstallCommand('steamcmd +app_update 740 validate +quit');

        $command = $resolver->resolveInstallCommand($instance);

        self::assertSame('steamcmd +app_update 740 validate +quit', $command);
    }

    private function buildResolver(MinecraftVersionCatalogRepositoryInterface $repository, ?JavaBinaryConfig $config = null): TemplateInstallResolver
    {
        $catalogService = new MinecraftCatalogService($repository);

        return new TemplateInstallResolver($catalogService, null, $config);
    }

    /**
     * @param array<string, mixed> $installResolver
     */
    private function buildInstance(array $installResolver, string $os): Instance
    {
        $template = new Template(
            'minecraft',
            'Minecraft',
            null,
            null,
            null,
            [
                ['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp'],
            ],
            'java -jar server.jar nogui',
            [],
            [],
            [],
            [],
            'install handled by catalog resolver',
            'update handled by catalog resolver',
            $installResolver,
            [],
            [],
            [],
            ['linux', 'windows'],
            [],
            [],
        );

        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', [
            'key_id' => 'key-1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ]);
        $agent->recordHeartbeat(['os' => $os], '1.0.0', null);

        return new Instance(
            $customer,
            $template,
            $agent,
            100,
            1024,
            10240,
            null,
            InstanceStatus::Stopped,
            InstanceUpdatePolicy::Manual,
        );
    }
}

final class InMemoryMinecraftCatalogRepository implements MinecraftVersionCatalogRepositoryInterface
{
    /**
     * @var array<int, MinecraftVersionCatalog>
     */
    private array $entries = [];

    public function add(MinecraftVersionCatalog $entry): void
    {
        $this->entries[] = $entry;
    }

    public function findVersionsByChannel(string $channel, bool $activeOnly = true): array
    {
        $versions = [];
        foreach ($this->entries as $entry) {
            if ($entry->getChannel() !== $channel) {
                continue;
            }
            $timestamp = $entry->getReleasedAt()?->getTimestamp() ?? 0;
            if (!isset($versions[$entry->getMcVersion()]) || $timestamp > $versions[$entry->getMcVersion()]) {
                $versions[$entry->getMcVersion()] = $timestamp;
            }
        }

        arsort($versions);

        return array_keys($versions);
    }

    public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array
    {
        $grouped = [];
        foreach ($this->entries as $entry) {
            if ($entry->getChannel() !== $channel) {
                continue;
            }
            $build = $entry->getBuild();
            if ($build === null || $build === '') {
                continue;
            }
            $grouped[$entry->getMcVersion()][] = $build;
        }

        foreach ($grouped as $version => $builds) {
            $uniqueBuilds = array_values(array_unique($builds));
            rsort($uniqueBuilds, SORT_NATURAL);
            $grouped[$version] = $uniqueBuilds;
        }

        return $grouped;
    }

    public function findActiveByChannel(string $channel): array
    {
        return array_values(array_filter($this->entries, static fn (MinecraftVersionCatalog $entry): bool => $entry->getChannel() === $channel && $entry->isActive()));
    }

    public function findLatestVersion(string $channel, bool $activeOnly = true): ?string
    {
        $versions = $this->findVersionsByChannel($channel);

        return $versions[0] ?? null;
    }

    public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string
    {
        $entries = array_filter($this->entries, static fn (MinecraftVersionCatalog $entry): bool => $entry->getChannel() === $channel && $entry->getMcVersion() === $version);
        if ($entries === []) {
            return null;
        }

        usort($entries, static function (MinecraftVersionCatalog $a, MinecraftVersionCatalog $b): int {
            $aTimestamp = $a->getReleasedAt()?->getTimestamp() ?? 0;
            $bTimestamp = $b->getReleasedAt()?->getTimestamp() ?? 0;
            if ($aTimestamp !== $bTimestamp) {
                return $bTimestamp <=> $aTimestamp;
            }
            return strcmp((string) ($b->getBuild() ?? ''), (string) ($a->getBuild() ?? ''));
        });

        return $entries[0]->getBuild();
    }

    public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?MinecraftVersionCatalog
    {
        if ($build !== null && $build !== '') {
            foreach ($this->entries as $entry) {
                if ($entry->getChannel() === $channel && $entry->getMcVersion() === $version && $entry->getBuild() === $build) {
                    return $entry;
                }
            }
            return null;
        }

        $candidates = array_filter(
            $this->entries,
            static fn (MinecraftVersionCatalog $entry): bool => $entry->getChannel() === $channel && $entry->getMcVersion() === $version,
        );

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (MinecraftVersionCatalog $a, MinecraftVersionCatalog $b): int {
            $aTimestamp = $a->getReleasedAt()?->getTimestamp() ?? 0;
            $bTimestamp = $b->getReleasedAt()?->getTimestamp() ?? 0;
            if ($aTimestamp !== $bTimestamp) {
                return $bTimestamp <=> $aTimestamp;
            }
            return strcmp((string) ($b->getBuild() ?? ''), (string) ($a->getBuild() ?? ''));
        });

        return $candidates[0] ?? null;
    }

    public function versionExists(string $channel, string $version, bool $activeOnly = true): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->getChannel() === $channel && $entry->getMcVersion() === $version) {
                return true;
            }
        }

        return false;
    }

    public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->getChannel() === $channel && $entry->getMcVersion() === $version && $entry->getBuild() === $build) {
                return true;
            }
        }

        return false;
    }
}
