<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\InstanceBaseDirectory;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\GameserverInstallDirectory;
use App\Module\Gameserver\Application\GameServerInstallPathManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GameserverInstallDirectoryTest extends TestCase
{
    public function testNodeDirectoryWinsOverTheRelativeInstallerDefault(): void
    {
        $settings = $this->createMock(AppSettingsServiceInterface::class);
        $settings->expects(self::never())->method('getInstanceBaseDir');
        $node = new Agent('Games', []);
        $node->setMetadata(['instance_base_dirs' => ['/mnt/games', '/srv/second-disk']]);
        $directories = new GameserverInstallDirectory($settings);
        self::assertSame('/mnt/games', $directories->resolve($node));
        self::assertSame('/srv/second-disk', $directories->resolve($node, '/srv/second-disk'));
    }

    public function testAnExplicitDirectoryMustBelongToTheSelectedNode(): void
    {
        $directories = new GameserverInstallDirectory($this->createStub(AppSettingsServiceInterface::class));
        $node = new Agent('Games', []);
        $node->setMetadata(['instance_base_dirs' => ['/srv/games']]);
        $this->expectException(\DomainException::class);
        $directories->resolve($node, '/mnt/other-node');
    }

    public function testAValidGlobalDirectoryRemainsAvailableForNodesWithoutOverrides(): void
    {
        $settings = $this->createStub(AppSettingsServiceInterface::class);
        $settings->method('getInstanceBaseDir')->willReturn('/custom/games');
        self::assertSame('/custom/games', (new GameserverInstallDirectory($settings))->resolve(new Agent('Games', [])));
    }

    public function testAnUnknownRelativeDirectoryIsNotTurnedIntoAVarDirectory(): void
    {
        $settings = $this->createStub(AppSettingsServiceInterface::class);
        $settings->method('getInstanceBaseDir')->willReturn('var/easywi');
        $this->expectException(\DomainException::class);
        (new GameserverInstallDirectory($settings))->resolve(new Agent('Games', []));
    }

    public function testExistingInstallPathsAreNeverMoved(): void
    {
        $settings = $this->createMock(AppSettingsServiceInterface::class);
        $settings->expects(self::never())->method('getInstanceBaseDir');
        $instance = $this->createMock(Instance::class);
        $instance->method('getInstallPath')->willReturn('/existing/custom/server');
        $instance->expects(self::never())->method('setInstallPath');
        $instance->expects(self::never())->method('setInstanceBaseDir');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        (new GameServerInstallPathManager(new InstanceFilesystemResolver($settings), $logger))->ensureInstallPath($instance);
    }

    public static function invalidDirectories(): iterable
    {
        foreach (['var/easywi', 'relative/games', '../games', '/srv/../games', '/srv/./games', "bad\0path", 'C:relative', ''] as $path) {
            yield 'path_'.bin2hex($path) => [$path];
        }
    }

    #[DataProvider('invalidDirectories')]
    public function testInvalidDirectoriesAreRejected(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InstanceBaseDirectory::normalize($path);
    }

    public function testWindowsDrivePathsKeepTheirConfiguredLocation(): void
    {
        self::assertSame('D:\\Games\\instances', InstanceBaseDirectory::normalize('D:/Games/instances'));
        self::assertSame('D:\\Games\\instances', InstanceBaseDirectory::normalize('D:\\Games\\instances'));
    }
}
