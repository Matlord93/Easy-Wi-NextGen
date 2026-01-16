<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Agent;
use App\Entity\Instance;
use App\Entity\MinecraftVersionCatalog;
use App\Entity\Template;
use App\Entity\User;
use App\Enum\InstanceStatus;
use App\Enum\InstanceUpdatePolicy;
use App\Enum\UserType;
use App\Repository\MinecraftVersionCatalogRepositoryInterface;
use App\Service\Installer\TemplateInstallResolver;
use App\Service\MinecraftCatalogService;
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

    private function buildResolver(MinecraftVersionCatalogRepositoryInterface $repository): TemplateInstallResolver
    {
        $catalogService = new MinecraftCatalogService($repository);

        return new TemplateInstallResolver($catalogService);
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
            'install',
            'update',
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

    public function findVersionsByChannel(string $channel): array
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

    public function findBuildsGroupedByVersion(string $channel): array
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

    public function findLatestVersion(string $channel): ?string
    {
        $versions = $this->findVersionsByChannel($channel);

        return $versions[0] ?? null;
    }

    public function findLatestBuild(string $channel, string $version): ?string
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

    public function findEntry(string $channel, string $version, ?string $build): ?MinecraftVersionCatalog
    {
        if ($build !== null && $build !== '') {
            foreach ($this->entries as $entry) {
                if ($entry->getChannel() === $channel && $entry->getMcVersion() === $version && $entry->getBuild() === $build) {
                    return $entry;
                }
            }
            return null;
        }

        $latestBuild = $this->findLatestBuild($channel, $version);
        if ($latestBuild === null) {
            return null;
        }

        return $this->findEntry($channel, $version, $latestBuild);
    }

    public function versionExists(string $channel, string $version): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->getChannel() === $channel && $entry->getMcVersion() === $version) {
                return true;
            }
        }

        return false;
    }

    public function buildExists(string $channel, string $version, string $build): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->getChannel() === $channel && $entry->getMcVersion() === $version && $entry->getBuild() === $build) {
                return true;
            }
        }

        return false;
    }
}
