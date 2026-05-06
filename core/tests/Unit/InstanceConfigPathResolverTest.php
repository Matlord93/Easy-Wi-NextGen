<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Application\InstanceConfigPathResolver;
use PHPUnit\Framework\TestCase;

final class InstanceConfigPathResolverTest extends TestCase
{
    public function testResolverBlocksTraversal(): void
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInstanceBaseDir')->willReturn('/srv/instances');
        $resolver = new InstanceConfigPathResolver(new InstanceFilesystemResolver($settings));

        $node = $this->createMock(Agent::class);
        $node->method('getMetadata')->willReturn(['os' => 'linux']);
        $node->method('getLastHeartbeatStats')->willReturn(['os' => 'linux']);

        $instance = $this->createMock(Instance::class);
        $instance->method('getInstallPath')->willReturn('/srv/instances/gs1');
        $instance->method('getNode')->willReturn($node);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PATH_TRAVERSAL');
        $resolver->resolve($instance, '../outside.cfg');
    }

    public function testResolverSupportsWindowsPaths(): void
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInstanceBaseDir')->willReturn('C:/EasyWI/instances');
        $resolver = new InstanceConfigPathResolver(new InstanceFilesystemResolver($settings));

        $node = $this->createMock(Agent::class);
        $node->method('getMetadata')->willReturn(['os' => 'windows']);
        $node->method('getLastHeartbeatStats')->willReturn(['os' => 'windows']);

        $instance = $this->createMock(Instance::class);
        $instance->method('getInstallPath')->willReturn('C:/EasyWI/instances/gs1');
        $instance->method('getNode')->willReturn($node);

        $resolved = $resolver->resolve($instance, 'cfg/server.cfg');

        self::assertSame('windows', $resolved['os']);
        self::assertSame('C:/EasyWI/instances/gs1/cfg/server.cfg', $resolved['absolute']);
    }

    public function testResolverBlocksAbsoluteTargetPath(): void
    {
        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getInstanceBaseDir')->willReturn('/srv/instances');
        $resolver = new InstanceConfigPathResolver(new InstanceFilesystemResolver($settings));

        $node = $this->createMock(Agent::class);
        $node->method('getMetadata')->willReturn(['os' => 'linux']);
        $node->method('getLastHeartbeatStats')->willReturn(['os' => 'linux']);

        $instance = $this->createMock(Instance::class);
        $instance->method('getInstallPath')->willReturn('/srv/instances/gs1');
        $instance->method('getNode')->willReturn($node);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PATH_TRAVERSAL');
        $resolver->resolve($instance, '/etc/passwd');
    }

}
