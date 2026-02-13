<?php

declare(strict_types=1);

namespace App\Tests\Module\Gameserver\Application;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Gameserver\Application\GameServerPathResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GameServerPathResolverTest extends TestCase
{
    public function testResolveRootUsesPersistedInstallPathWhenAvailable(): void
    {
        $resolver = new GameServerPathResolver(
            $this->newFilesystemResolver('/srv/default'),
            $this->createMock(LoggerInterface::class),
        );

        $instance = $this->createMock(Instance::class);
        $instance->method('getInstallPath')->willReturn('/srv/servers/gs42');

        self::assertSame('/srv/servers/gs42', $resolver->resolveRoot($instance));
    }

    public function testResolveRootBootstrapsLegacyPathWhenInstallPathMissing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $instance = $this->createMock(Instance::class);
        $instance->method('getInstallPath')->willReturn('');
        $instance->method('getInstanceBaseDir')->willReturn('/srv/games');
        $instance->expects(self::once())->method('setInstallPath')->with('/srv/games/gs97');
        $instance->method('getId')->willReturn(7);

        $customer = $this->createMock(User::class);
        $customer->method('getId')->willReturn(9);
        $instance->method('getCustomer')->willReturn($customer);

        $resolver = new GameServerPathResolver($this->newFilesystemResolver('/srv/default'), $logger);

        self::assertSame('/srv/games/gs97', $resolver->resolveRoot($instance));
    }

    public function testResolveRootRejectsRelativePath(): void
    {
        $resolver = new GameServerPathResolver(
            $this->newFilesystemResolver('/srv/default'),
            $this->createMock(LoggerInterface::class),
        );

        $instance = $this->createMock(Instance::class);
        $instance->method('getInstallPath')->willReturn('relative/path');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('INVALID_SERVER_ROOT');

        $resolver->resolveRoot($instance);
    }

    private function newFilesystemResolver(string $defaultBaseDir): InstanceFilesystemResolver
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInstanceBaseDir')->willReturn($defaultBaseDir);

        return new InstanceFilesystemResolver($settings);
    }
}
