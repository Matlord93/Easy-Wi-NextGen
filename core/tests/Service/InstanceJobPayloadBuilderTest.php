<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\InstanceJobPayloadBuilder;
use App\Module\Gameserver\Application\JavaBinaryConfig;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Application\MinecraftJavaVersionResolver;
use App\Module\Gameserver\Application\TemplateInstallResolver;
use App\Module\Ports\Infrastructure\Repository\PortBlockFinderInterface;
use App\Repository\MinecraftVersionCatalogRepositoryInterface;
use App\Repository\SharedStorageTemplateLocatorInterface;
use PHPUnit\Framework\TestCase;

final class InstanceJobPayloadBuilderTest extends TestCase
{
    public function testRuntimePayloadUsesUpdatedSetupVars(): void
    {
        $template = new Template(
            'game',
            'Game',
            null,
            null,
            null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'udp']],
            '{{SERVER_NAME}} {{CUSTOM_VAR}}',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Default Name'],
                ['key' => 'CUSTOM_VAR', 'value' => 'default'],
            ],
            [],
            [],
            [],
            'install',
            'update',
            [],
            [],
            [],
            [],
            ['linux'],
            [],
            [],
        );

        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', [
            'key_id' => 'key-1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ]);
        $instance = new Instance(
            $customer,
            $template,
            $agent,
            100,
            1024,
            10240,
            null,
            InstanceStatus::Running,
            InstanceUpdatePolicy::Manual,
        );
        $instance->setSetupVars([
            'CUSTOM_VAR' => 'updated',
            'SERVER_NAME' => 'My Server',
        ]);
        $instance->setServerName('My Server');
        $instance->setConfigOverride('server.cfg', 'sv_hostname "My Server"');

        $catalogRepo = new class () implements MinecraftVersionCatalogRepositoryInterface {
            public function findVersionsByChannel(string $channel, bool $activeOnly = true): array
            {
                return [];
            }

            public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array
            {
                return [];
            }

            public function findActiveByChannel(string $channel): array
            {
                return [];
            }

            public function findLatestVersion(string $channel, bool $activeOnly = true): ?string
            {
                return null;
            }

            public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string
            {
                return null;
            }

            public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog
            {
                return null;
            }

            public function versionExists(string $channel, string $version, bool $activeOnly = true): bool
            {
                return false;
            }

            public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool
            {
                return false;
            }
        };
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo));
        $portBlockRepository = new class () implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock
            {
                return null;
            }
        };
        $builder = new InstanceJobPayloadBuilder($resolver, $portBlockRepository, self::buildTemplateRepository());

        $payload = $builder->buildRuntimePayload($instance);

        $envVars = [];
        foreach ($payload['env_vars'] as $entry) {
            $envVars[$entry['key']] = $entry['value'];
        }

        self::assertSame('updated', $envVars['CUSTOM_VAR'] ?? null);
        self::assertSame('My Server', $envVars['SERVER_NAME'] ?? null);
        self::assertSame('server.cfg', $payload['config_files'][0]['path'] ?? null);
        self::assertSame(base64_encode('sv_hostname "My Server"'), $payload['config_files'][0]['content_base64'] ?? null);
    }

    public function testSniperPayloadDisablesAutostartByDefault(): void
    {
        $template = new Template(
            'game',
            'Game',
            null,
            null,
            null,
            [],
            '{{SERVER_NAME}}',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Default Name'],
            ],
            [],
            [],
            [],
            'install',
            'update',
            [],
            [],
            [],
            [],
            ['linux'],
            [],
            [],
        );

        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', [
            'key_id' => 'key-1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ]);
        $instance = new Instance(
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

        $catalogRepo = new class () implements MinecraftVersionCatalogRepositoryInterface {
            public function findVersionsByChannel(string $channel, bool $activeOnly = true): array
            {
                return [];
            }
            public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array
            {
                return [];
            }
            public function findActiveByChannel(string $channel): array
            {
                return [];
            }

            public function findLatestVersion(string $channel, bool $activeOnly = true): ?string
            {
                return null;
            }
            public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string
            {
                return null;
            }
            public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog
            {
                return null;
            }
            public function versionExists(string $channel, string $version, bool $activeOnly = true): bool
            {
                return false;
            }
            public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool
            {
                return false;
            }
        };
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo));
        $portBlockRepository = new class () implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock
            {
                return null;
            }
        };
        $builder = new InstanceJobPayloadBuilder($resolver, $portBlockRepository, self::buildTemplateRepository());

        $installPayload = $builder->buildSniperInstallPayload($instance);
        $updatePayload = $builder->buildSniperUpdatePayload($instance);

        self::assertSame('false', $installPayload['autostart'] ?? null);
        self::assertSame('false', $updatePayload['autostart'] ?? null);
        self::assertSame('game', $installPayload['game_type'] ?? null);
        self::assertSame('none', $installPayload['shared_runtime_mode'] ?? null);
    }

    public function testSniperPayloadIncludesSharedStorageWhenEnabled(): void
    {
        $template = new Template(
            'game',
            'Game',
            null,
            null,
            null,
            [],
            '{{SERVER_NAME}}',
            [['key' => 'SERVER_NAME', 'value' => 'Default Name']],
            [],
            [],
            [],
            'install',
            'update',
            [],
            [],
            [],
            [],
            ['linux'],
            [],
            ['shared_paths' => [['source' => 'maps', 'target' => 'maps', 'mode' => 'symlink', 'readonly' => true]]],
        );
        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', ['key_id' => 'key-1', 'nonce' => 'nonce', 'ciphertext' => 'ciphertext']);
        $instance = new Instance($customer, $template, $agent, 100, 1024, 10240, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService(new class () implements MinecraftVersionCatalogRepositoryInterface {
            public function findVersionsByChannel(string $channel, bool $activeOnly = true): array { return []; }
            public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array { return []; }
            public function findActiveByChannel(string $channel): array
            {
                return [];
            }

            public function findLatestVersion(string $channel, bool $activeOnly = true): ?string { return null; }
            public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string { return null; }
            public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog { return null; }
            public function versionExists(string $channel, string $version, bool $activeOnly = true): bool { return false; }
            public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool { return false; }
        }));
        $builder = new InstanceJobPayloadBuilder($resolver, new class () implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock { return null; }
        }, self::buildTemplateRepository());
        $payload = $builder->buildSniperInstallPayload($instance, true);
        self::assertArrayHasKey('template_id', $payload);
        self::assertNotEmpty($payload['shared_paths'] ?? []);
    }



    public function testSniperPayloadIncludesInstallPathWhenSet(): void
    {
        $template = new Template(
            'game',
            'Game',
            null,
            null,
            null,
            [],
            '{{SERVER_NAME}}',
            [
                ['key' => 'SERVER_NAME', 'value' => 'Default Name'],
            ],
            [],
            [],
            [],
            'install',
            'update',
            [],
            [],
            [],
            [],
            ['linux'],
            [],
            [],
        );

        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', [
            'key_id' => 'key-1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ]);
        $instance = new Instance(
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
        $instance->setInstallPath('/srv/instances/gs12');

        $catalogRepo = new class () implements MinecraftVersionCatalogRepositoryInterface {
            public function findVersionsByChannel(string $channel, bool $activeOnly = true): array
            {
                return [];
            }
            public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array
            {
                return [];
            }
            public function findActiveByChannel(string $channel): array
            {
                return [];
            }

            public function findLatestVersion(string $channel, bool $activeOnly = true): ?string
            {
                return null;
            }
            public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string
            {
                return null;
            }
            public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog
            {
                return null;
            }
            public function versionExists(string $channel, string $version, bool $activeOnly = true): bool
            {
                return false;
            }
            public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool
            {
                return false;
            }
        };
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo));
        $portBlockRepository = new class () implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock
            {
                return null;
            }
        };
        $builder = new InstanceJobPayloadBuilder($resolver, $portBlockRepository, self::buildTemplateRepository());

        $installPayload = $builder->buildSniperInstallPayload($instance);

        self::assertSame('/srv/instances/gs12', $installPayload['install_path'] ?? null);
    }

    public function testRuntimePayloadProvidesEmptyRconPasswordWhenUnset(): void
    {
        $template = new Template(
            'game',
            'Game',
            null,
            null,
            null,
            [],
            '{{RCON_PASSWORD}}',
            [
                ['key' => 'RCON_PASSWORD', 'value' => 'change-me'],
            ],
            [],
            [],
            [],
            'install',
            'update',
            [],
            [],
            [],
            [],
            ['linux'],
            [],
            [],
        );

        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', [
            'key_id' => 'key-1',
            'nonce' => 'nonce',
            'ciphertext' => 'ciphertext',
        ]);
        $instance = new Instance(
            $customer,
            $template,
            $agent,
            100,
            1024,
            10240,
            null,
            InstanceStatus::Running,
            InstanceUpdatePolicy::Manual,
        );

        $catalogRepo = new class () implements MinecraftVersionCatalogRepositoryInterface {
            public function findVersionsByChannel(string $channel, bool $activeOnly = true): array
            {
                return [];
            }

            public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array
            {
                return [];
            }

            public function findActiveByChannel(string $channel): array
            {
                return [];
            }

            public function findLatestVersion(string $channel, bool $activeOnly = true): ?string
            {
                return null;
            }

            public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string
            {
                return null;
            }

            public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog
            {
                return null;
            }

            public function versionExists(string $channel, string $version, bool $activeOnly = true): bool
            {
                return false;
            }

            public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool
            {
                return false;
            }
        };
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo));
        $portBlockRepository = new class () implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock
            {
                return null;
            }
        };
        $builder = new InstanceJobPayloadBuilder($resolver, $portBlockRepository, self::buildTemplateRepository());

        $payload = $builder->buildRuntimePayload($instance);

        $envVars = [];
        foreach ($payload['env_vars'] as $entry) {
            $envVars[$entry['key']] = $entry['value'];
        }

        self::assertArrayHasKey('RCON_PASSWORD', $envVars);
        self::assertSame('', $envVars['RCON_PASSWORD']);
    }


    public function testSniperPayloadIncludesJavaBinWhenStartParamsReferenceItWithoutCatalogResolver(): void
    {
        $startParams = '{{JAVA_BIN}} -Xms512m -Xmx1024m -jar {{INSTANCE_DIR}}/server.jar nogui';
        $template = new Template(
            'minecraft_vanilla_all',
            'Minecraft Java',
            null,
            null,
            null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp']],
            $startParams,
            [],
            [],
            [],
            [],
            'install handled by catalog resolver',
            'update handled by catalog resolver',
            [],
            [],
            [],
            [],
            ['linux'],
            [],
            [],
        );

        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', ['key_id' => 'key-1', 'nonce' => 'nonce', 'ciphertext' => 'ciphertext']);
        $instance = new Instance($customer, $template, $agent, 100, 1024, 10240, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);

        $catalogRepo = new class () implements MinecraftVersionCatalogRepositoryInterface {
            public function findVersionsByChannel(string $channel, bool $activeOnly = true): array { return []; }
            public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array { return []; }
            public function findActiveByChannel(string $channel): array { return []; }
            public function findLatestVersion(string $channel, bool $activeOnly = true): ?string { return null; }
            public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string { return null; }
            public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog { return null; }
            public function versionExists(string $channel, string $version, bool $activeOnly = true): bool { return false; }
            public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool { return false; }
        };
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo));
        $portBlockRepository = new class () implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock { return null; }
        };
        $builder = new InstanceJobPayloadBuilder($resolver, $portBlockRepository, self::buildTemplateRepository());

        $payload = $builder->buildSniperInstallPayload($instance);

        self::assertArrayHasKey('JAVA_BIN', $payload, 'JAVA_BIN must be a top-level payload variable when start_params references {{JAVA_BIN}}');
        self::assertNotEmpty($payload['JAVA_BIN']);
        self::assertArrayHasKey('java_bin', $payload);
    }

    public function testEnvVarsContainsJavaBinForVanillaTemplate(): void
    {
        $startParams = '{{JAVA_BIN}} -Xms512m -Xmx1024m -jar {{INSTANCE_DIR}}/server.jar nogui';
        $template = new Template(
            'minecraft_vanilla_all',
            'Minecraft Java (Vanilla)',
            null, null, null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp']],
            $startParams,
            [],
            [], [], [],
            'install handled by catalog resolver',
            'update handled by catalog resolver',
            ['type' => 'minecraft_vanilla'],
            [], [], [],
            ['linux'],
            [], [],
        );

        $instance = self::buildMinecraftInstance($template);
        $builder = self::buildPayloadBuilder();
        $payload = $builder->buildSniperInstallPayload($instance);

        $envVars = self::extractEnvVars($payload);
        self::assertArrayHasKey('JAVA_BIN', $envVars, 'JAVA_BIN must be in env_vars for agent template rendering');
        self::assertNotEmpty($envVars['JAVA_BIN']);
    }

    public function testEnvVarsContainsJavaBinForPaperTemplate(): void
    {
        $startParams = '{{JAVA_BIN}} -Xms1G -Xmx2G -jar {{INSTANCE_DIR}}/server.jar nogui';
        $template = new Template(
            'minecraft_paper_all',
            'Minecraft Java (Paper)',
            null, null, null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp']],
            $startParams,
            [],
            [], [], [],
            'install handled by catalog resolver',
            'update handled by catalog resolver',
            ['type' => 'papermc_paper'],
            [], [], [],
            ['linux'],
            [], [],
        );

        $instance = self::buildMinecraftInstance($template);
        $builder = self::buildPayloadBuilder();
        $payload = $builder->buildSniperInstallPayload($instance);

        $envVars = self::extractEnvVars($payload);
        self::assertArrayHasKey('JAVA_BIN', $envVars, 'JAVA_BIN must be in env_vars for paper template');
        self::assertNotEmpty($envVars['JAVA_BIN']);
    }

    public function testEnvVarsDoesNotContainJavaBinForBedrockTemplate(): void
    {
        $startParams = '{{INSTANCE_DIR}}/bedrock_server';
        $template = new Template(
            'minecraft_bedrock',
            'Minecraft Bedrock',
            null, null, null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'udp']],
            $startParams,
            [],
            [], [], [],
            'install handled by catalog resolver',
            'update handled by catalog resolver',
            ['type' => 'minecraft_bedrock'],
            [], [], [],
            ['linux'],
            [], [],
        );

        $instance = self::buildMinecraftInstance($template);
        $builder = self::buildPayloadBuilder();
        $payload = $builder->buildSniperInstallPayload($instance);

        $envVars = self::extractEnvVars($payload);
        self::assertArrayNotHasKey('JAVA_BIN', $envVars, 'Bedrock does not need Java');
        self::assertArrayNotHasKey('JAVA_BIN', $payload);
        self::assertArrayNotHasKey('java_bin', $payload);
    }

    public function testRuntimePayloadContainsJavaBin(): void
    {
        $startParams = '{{JAVA_BIN}} -jar {{INSTANCE_DIR}}/server.jar nogui';
        $template = new Template(
            'minecraft_vanilla_all',
            'Minecraft Java',
            null, null, null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp']],
            $startParams,
            [],
            [], [], [],
            'install handled by catalog resolver',
            'update handled by catalog resolver',
            ['type' => 'minecraft_vanilla'],
            [], [], [],
            ['linux'],
            [], [],
        );

        $instance = self::buildMinecraftInstance($template);
        $builder = self::buildPayloadBuilder();
        $payload = $builder->buildRuntimePayload($instance);

        $envVars = self::extractEnvVars($payload);
        self::assertArrayHasKey('JAVA_BIN', $envVars, 'JAVA_BIN must be present in runtime payload env_vars');
        self::assertNotEmpty($envVars['JAVA_BIN']);
    }

    public function testFallbackJavaBinIsJava21WhenNoVersionResolvable(): void
    {
        $startParams = '{{JAVA_BIN}} -jar server.jar';
        $template = new Template(
            'minecraft_vanilla_all',
            'Minecraft Java',
            null, null, null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp']],
            $startParams,
            [],
            [], [], [],
            'install handled by catalog resolver',
            'update handled by catalog resolver',
            [],
            [], [], [],
            ['linux'],
            [], [],
        );

        $instance = self::buildMinecraftInstance($template);
        $builder = self::buildPayloadBuilder();
        $payload = $builder->buildSniperInstallPayload($instance);

        $envVars = self::extractEnvVars($payload);
        self::assertSame('java21', $envVars['JAVA_BIN'] ?? null, 'Fallback must be java21 when no version is resolvable');
    }

    public function testEnvVarsJavaBinNotSuppressedByEmptySetupVar(): void
    {
        $startParams = '{{JAVA_BIN}} -Xms1G -Xmx2G -jar {{INSTANCE_DIR}}/server.jar nogui';
        $template = new Template(
            'minecraft_vanilla_all',
            'Minecraft Java (Vanilla)',
            null, null, null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp']],
            $startParams,
            [],
            [], [], [],
            'install handled by catalog resolver',
            'update handled by catalog resolver',
            ['type' => 'minecraft_vanilla'],
            [], [], [],
            ['linux'],
            [], [],
        );

        $instance = self::buildMinecraftInstance($template);
        $instance->setSetupVars(['JAVA_BIN' => '']);

        $builder = self::buildPayloadBuilder();
        $payload = $builder->buildSniperInstallPayload($instance);

        $envVars = self::extractEnvVars($payload);
        self::assertArrayHasKey('JAVA_BIN', $envVars, 'JAVA_BIN must be set even when setupVars contains an empty value');
        self::assertNotEmpty($envVars['JAVA_BIN'], 'JAVA_BIN must be non-empty so the agent can render {{JAVA_BIN}} in start_params');
    }

    public function testCustomJavaBinaryConfigIsUsedInPayload(): void
    {
        $startParams = '{{JAVA_BIN}} -jar {{INSTANCE_DIR}}/server.jar nogui';
        $template = new Template(
            'minecraft_vanilla_all',
            'Minecraft Java',
            null, null, null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'tcp']],
            $startParams,
            [],
            [], [], [],
            'install handled by catalog resolver',
            'update handled by catalog resolver',
            ['type' => 'minecraft_vanilla'],
            [], [], [],
            ['linux'],
            [], [],
        );

        $instance = self::buildMinecraftInstance($template);

        $config = new JavaBinaryConfig(['21' => '/usr/lib/jvm/java-21-openjdk-amd64/bin/java']);
        $catalogRepo = self::buildEmptyCatalogRepo();
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo), null, $config);
        $portRepo = new class () implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock { return null; }
        };
        $javaResolver = new MinecraftJavaVersionResolver($config);
        $builder = new InstanceJobPayloadBuilder($resolver, $portRepo, self::buildTemplateRepository(), null, $javaResolver, $config);

        $payload = $builder->buildSniperInstallPayload($instance);

        $envVars = self::extractEnvVars($payload);
        self::assertSame('/usr/lib/jvm/java-21-openjdk-amd64/bin/java', $envVars['JAVA_BIN'] ?? null);
        self::assertSame('/usr/lib/jvm/java-21-openjdk-amd64/bin/java', $payload['JAVA_BIN'] ?? null);
    }

    private static function buildMinecraftInstance(Template $template): Instance
    {
        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', ['key_id' => 'key-1', 'nonce' => 'nonce', 'ciphertext' => 'ciphertext']);

        return new Instance($customer, $template, $agent, 100, 1024, 10240, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
    }

    private static function buildPayloadBuilder(): InstanceJobPayloadBuilder
    {
        $catalogRepo = self::buildEmptyCatalogRepo();
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo));
        $portRepo = new class () implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock { return null; }
        };

        return new InstanceJobPayloadBuilder($resolver, $portRepo, self::buildTemplateRepository());
    }

    private static function buildEmptyCatalogRepo(): MinecraftVersionCatalogRepositoryInterface
    {
        return new class () implements MinecraftVersionCatalogRepositoryInterface {
            public function findVersionsByChannel(string $channel, bool $activeOnly = true): array { return []; }
            public function findBuildsGroupedByVersion(string $channel, bool $activeOnly = true): array { return []; }
            public function findActiveByChannel(string $channel): array { return []; }
            public function findLatestVersion(string $channel, bool $activeOnly = true): ?string { return null; }
            public function findLatestBuild(string $channel, string $version, bool $activeOnly = true): ?string { return null; }
            public function findEntry(string $channel, string $version, ?string $build, bool $activeOnly = true): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog { return null; }
            public function versionExists(string $channel, string $version, bool $activeOnly = true): bool { return false; }
            public function buildExists(string $channel, string $version, string $build, bool $activeOnly = true): bool { return false; }
        };
    }

    /** @return array<string, string> */
    private static function extractEnvVars(array $payload): array
    {
        $envVars = [];
        foreach ($payload['env_vars'] ?? [] as $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $envVars[$entry['key']] = $entry['value'] ?? '';
            }
        }
        return $envVars;
    }

    private static function buildTemplateRepository(): SharedStorageTemplateLocatorInterface
    {
        return new class () implements SharedStorageTemplateLocatorInterface {
            public function findSharedStorageVariantForIdentity(\App\Module\Core\Domain\Entity\Template $template): ?\App\Module\Core\Domain\Entity\Template
            {
                return null;
            }
        };
    }

}
