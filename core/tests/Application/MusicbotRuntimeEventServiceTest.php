<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotRuntimeEventService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Repository\MusicbotRuntimeEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MusicbotRuntimeEventServiceTest extends TestCase
{
    public function testSanitizeMasksNestedSecrets(): void
    {
        $service = new MusicbotRuntimeEventService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MusicbotRuntimeEventRepository::class),
        );

        self::assertSame([
            'discord_token' => '********',
            'teamspeak' => [
                'server_password' => '********',
                'channel_password' => '********',
                'profile' => 'ts3',
            ],
            'plugin' => [
                'apiSecret' => '********',
            ],
        ], $service->sanitize([
            'discord_token' => 'secret-token',
            'teamspeak' => [
                'server_password' => 'server-secret',
                'channel_password' => 'channel-secret',
                'profile' => 'ts3',
            ],
            'plugin' => [
                'apiSecret' => 'plugin-secret',
            ],
        ]));
    }

    public function testRuntimeEventKeepsInstanceCustomerForTenantIsolation(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('agent-1', ['token' => 'hash'], 'Agent 1');
        $instance = new MusicbotInstance($customer, $agent, 'Musicbot', 'musicbot-demo', '/srv/musicbot/demo');

        $service = new MusicbotRuntimeEventService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MusicbotRuntimeEventRepository::class),
        );

        $event = $service->record($instance, 'queue.updated', 'info', 'Queue changed.', ['token' => 'must-mask']);

        self::assertSame($instance, $event->getMusicbotInstance());
        self::assertSame($customer, $event->getCustomer());
        self::assertSame(['token' => '********'], $event->getContext());
    }
}
