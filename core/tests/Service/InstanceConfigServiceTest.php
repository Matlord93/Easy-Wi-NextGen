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
}
