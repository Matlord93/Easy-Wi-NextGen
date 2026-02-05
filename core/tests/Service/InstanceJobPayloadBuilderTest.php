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
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Application\TemplateInstallResolver;
use App\Module\Ports\Infrastructure\Repository\PortBlockFinderInterface;
use App\Repository\MinecraftVersionCatalogRepositoryInterface;
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

        $catalogRepo = new class implements MinecraftVersionCatalogRepositoryInterface {
            public function findVersionsByChannel(string $channel): array
            {
                return [];
            }

            public function findBuildsGroupedByVersion(string $channel): array
            {
                return [];
            }

            public function findLatestVersion(string $channel): ?string
            {
                return null;
            }

            public function findLatestBuild(string $channel, string $version): ?string
            {
                return null;
            }

            public function findEntry(string $channel, string $version, ?string $build): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog
            {
                return null;
            }

            public function versionExists(string $channel, string $version): bool
            {
                return false;
            }

            public function buildExists(string $channel, string $version, string $build): bool
            {
                return false;
            }
        };
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo));
        $portBlockRepository = new class implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock
            {
                return null;
            }
        };
        $builder = new InstanceJobPayloadBuilder($resolver, $portBlockRepository);

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

        $catalogRepo = new class implements MinecraftVersionCatalogRepositoryInterface {
            public function findVersionsByChannel(string $channel): array { return []; }
            public function findBuildsGroupedByVersion(string $channel): array { return []; }
            public function findLatestVersion(string $channel): ?string { return null; }
            public function findLatestBuild(string $channel, string $version): ?string { return null; }
            public function findEntry(string $channel, string $version, ?string $build): ?\App\Module\Core\Domain\Entity\MinecraftVersionCatalog { return null; }
            public function versionExists(string $channel, string $version): bool { return false; }
            public function buildExists(string $channel, string $version, string $build): bool { return false; }
        };
        $resolver = new TemplateInstallResolver(new MinecraftCatalogService($catalogRepo));
        $portBlockRepository = new class implements PortBlockFinderInterface {
            public function findByInstance(Instance $instance): ?\App\Module\Ports\Domain\Entity\PortBlock
            {
                return null;
            }
        };
        $builder = new InstanceJobPayloadBuilder($resolver, $portBlockRepository);

        $installPayload = $builder->buildSniperInstallPayload($instance);
        $updatePayload = $builder->buildSniperUpdatePayload($instance);

        self::assertSame('false', $installPayload['autostart'] ?? null);
        self::assertSame('false', $updatePayload['autostart'] ?? null);
    }

}
