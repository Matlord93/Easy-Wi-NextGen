<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotRuntimeEventService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRuntimeEvent;
use App\Repository\MusicbotRuntimeEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MusicbotRuntimeEventServiceTest extends TestCase
{
    private function makeEventRepo(): MusicbotRuntimeEventRepository
    {
        return (new \ReflectionClass(MusicbotRuntimeEventRepository::class))->newInstanceWithoutConstructor();
    }

    public function testSanitizeMasksNestedSecrets(): void
    {
        $service = new MusicbotRuntimeEventService(
            $this->createStub(EntityManagerInterface::class),
            $this->makeEventRepo(),
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

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('persist')->with($this->callback(static function (MusicbotRuntimeEvent $event) use ($instance, $customer): bool {
            self::assertSame($instance, $event->getMusicbotInstance());
            self::assertSame($customer, $event->getCustomer());
            self::assertSame(['token' => '********'], $event->getContext());
            self::assertSame('Queue changed. bot_token=********', $event->getMessage());

            return true;
        }));
        $manager->expects($this->never())->method('flush');
        $service = new MusicbotRuntimeEventService(
            $manager,
            $this->makeEventRepo(),
        );

        $service->record($instance, 'queue.updated', 'info', 'Queue changed. bot_token=must-mask', ['token' => 'must-mask']);
    }
}
