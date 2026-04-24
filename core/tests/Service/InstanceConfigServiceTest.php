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
use App\Module\Gameserver\Application\InstanceConfigService;
use App\Module\Gameserver\Domain\Entity\GameProfile;
use App\Module\Gameserver\Domain\Enum\EnforceMode;
use App\Module\Ports\Domain\Entity\PortAllocation;
use PHPUnit\Framework\TestCase;

final class InstanceConfigServiceTest extends TestCase
{
    public function testSlotLimitUpdateAppliedToConfigPayload(): void
    {
        $template = new Template(
            'minecraft_vanilla_all',
            'Minecraft',
            null,
            null,
            null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'udp']],
            'start',
            [],
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
        $instance->setMaxSlots(32);
        $instance->setCurrentSlots(32);

        $profile = new GameProfile(
            'minecraft_vanilla_all',
            EnforceMode::EnforceByConfig,
            EnforceMode::EnforceByConfig,
            [],
            [],
        );

        $allocation = new PortAllocation(
            $instance,
            $agent,
            'GAME_PORT',
            'udp',
            25565,
            'static',
            true,
        );

        $service = new InstanceConfigService();
        $payload = $service->buildStartPayload($instance, $profile, [$allocation]);

        $configEntries = $payload['config'] ?? [];
        $serverProperties = $configEntries[0]['values'] ?? [];

        self::assertSame('32', $serverProperties['MAX_PLAYERS'] ?? null);
        self::assertSame('25565', $serverProperties['SERVER_PORT'] ?? null);
    }

    public function testUsesTemplateStartParamsAsDirectAgentCommand(): void
    {
        $template = new Template(
            'cs2',
            'Counter-Strike 2',
            null,
            null,
            null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'udp']],
            'srcds_linux -console -usercon',
            [],
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
        $instance->setCurrentSlots(24);

        $profile = new GameProfile(
            'cs2',
            EnforceMode::EnforceByArgs,
            EnforceMode::EnforceByArgs,
            [],
            [],
        );

        $allocation = new PortAllocation(
            $instance,
            $agent,
            'GAME_PORT',
            'udp',
            27015,
            'static',
            true,
        );

        $service = new InstanceConfigService();
        $payload = $service->buildStartPayload($instance, $profile, [$allocation]);

        self::assertSame('srcds_linux', $payload['command'] ?? null);
        self::assertSame('-console', $payload['args'][0] ?? null);
        self::assertSame('-usercon', $payload['args'][1] ?? null);
        self::assertContains('-port', $payload['args']);
        self::assertContains('-maxplayers', $payload['args']);
    }


    public function testParsesQuotedStartArgsWithoutShellWrapper(): void
    {
        $template = new Template(
            'cs2',
            'Counter-Strike 2',
            null,
            null,
            null,
            [['name' => 'game', 'label' => 'Game', 'protocol' => 'udp']],
            '{{INSTANCE_DIR}}/srcds_run +hostname "My Fancy Server" +map de_dust2',
            [],
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

        $profile = new GameProfile(
            'cs2',
            EnforceMode::EnforceByConfig,
            EnforceMode::EnforceByConfig,
            [],
            [],
        );

        $service = new InstanceConfigService();
        $payload = $service->buildStartPayload($instance, $profile, []);

        self::assertSame('{{INSTANCE_DIR}}/srcds_run', $payload['command'] ?? null);
        self::assertContains('+hostname', $payload['args']);
        self::assertContains('My Fancy Server', $payload['args']);
        self::assertContains('+map', $payload['args']);
    }

}
