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
        $settings = $this->createStub(AppSettingsService::class);
        $settings->method('getInstanceBaseDir')->willReturn('/srv/instances');
        $resolver = new InstanceConfigPathResolver(new InstanceFilesystemResolver($settings));

        $node = $this->createStub(Agent::class);
        $node->method('getMetadata')->willReturn(['os' => 'linux']);
        $node->method('getLastHeartbeatStats')->willReturn(['os' => 'linux']);

        $instance = $this->createStub(Instance::class);
        $instance->method('getInstallPath')->willReturn('/srv/instances/gs1');
        $instance->method('getNode')->willReturn($node);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PATH_TRAVERSAL');
        $resolver->resolve($instance, '../outside.cfg');
    }

    public function testResolverSupportsWindowsPaths(): void
    {
        $settings = $this->createStub(AppSettingsService::class);
        $settings->method('getInstanceBaseDir')->willReturn('C:/EasyWI/instances');
        $resolver = new InstanceConfigPathResolver(new InstanceFilesystemResolver($settings));

        $node = $this->createStub(Agent::class);
        $node->method('getMetadata')->willReturn(['os' => 'windows']);
        $node->method('getLastHeartbeatStats')->willReturn(['os' => 'windows']);

        $instance = $this->createStub(Instance::class);
        $instance->method('getInstallPath')->willReturn('C:/EasyWI/instances/gs1');
        $instance->method('getNode')->willReturn($node);

        $resolved = $resolver->resolve($instance, 'cfg/server.cfg');

        self::assertSame('windows', $resolved['os']);
        self::assertSame('C:/EasyWI/instances/gs1/cfg/server.cfg', $resolved['absolute']);
    }

    public function testResolverBlocksAbsoluteTargetPath(): void
    {
        $settings = $this->createStub(AppSettingsService::class);
        $settings->method('getInstanceBaseDir')->willReturn('/srv/instances');
        $resolver = new InstanceConfigPathResolver(new InstanceFilesystemResolver($settings));

        $node = $this->createStub(Agent::class);
        $node->method('getMetadata')->willReturn(['os' => 'linux']);
        $node->method('getLastHeartbeatStats')->willReturn(['os' => 'linux']);

        $instance = $this->createStub(Instance::class);
        $instance->method('getInstallPath')->willReturn('/srv/instances/gs1');
        $instance->method('getNode')->willReturn($node);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PATH_TRAVERSAL');
        $resolver->resolve($instance, '/etc/passwd');
    }

    public function testLocalConfigReadCannotFollowSymlinksOutsideInstance(): void
    {
        $root = sys_get_temp_dir().'/easywi-config-'.bin2hex(random_bytes(8));
        mkdir($root.'/instance', 0700, true);
        file_put_contents($root.'/secret', 'outside');
        try {
            if (!@symlink($root.'/secret', $root.'/instance/server.cfg')) { self::markTestSkipped('Symlinks unavailable.'); }
            [$resolver, $instance] = $this->localResolver($root.'/instance');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('PATH_TRAVERSAL');
            $resolver->read($instance, 'server.cfg');
        } finally {
            if (is_link($root.'/instance/server.cfg')) { unlink($root.'/instance/server.cfg'); }
            unlink($root.'/secret');
            rmdir($root.'/instance');
            rmdir($root);
        }
    }

    public function testLocalConfigReaderPreservesContentAndRejectsOversizedFiles(): void
    {
        $root = sys_get_temp_dir().'/easywi-config-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            file_put_contents($root.'/server.cfg', "name=<script>\n");
            [$resolver, $instance] = $this->localResolver($root);
            self::assertSame("name=<script>\n", $resolver->read($instance, 'server.cfg'));
            self::assertTrue($resolver->stat($instance, 'server.cfg')['exists']);
            file_put_contents($root.'/server.cfg', str_repeat('x', 256 * 1024 + 1));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('CONFIG_TOO_LARGE');
            $resolver->read($instance, 'server.cfg');
        } finally {
            unlink($root.'/server.cfg');
            rmdir($root);
        }
    }

    public function testStreamWrapperInstallRootIsRejectedBeforeReading(): void
    {
        [$resolver, $instance] = $this->localResolver('http://127.0.0.1:8006');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ROOT_INVALID');
        $resolver->read($instance, 'server.cfg');
    }

    /** @return array{InstanceConfigPathResolver, Instance} */
    private function localResolver(string $root): array
    {
        $settings = $this->createStub(AppSettingsService::class);
        $node = $this->createStub(Agent::class);
        $node->method('getMetadata')->willReturn(['os' => 'linux']);
        $instance = $this->createStub(Instance::class);
        $instance->method('getInstallPath')->willReturn($root);
        $instance->method('getNode')->willReturn($node);

        return [new InstanceConfigPathResolver(new InstanceFilesystemResolver($settings)), $instance];
    }

}
