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

    public function testLocalFilesAreCanonicalizedAndMissingFilesRemainUnavailable(): void
    {
        $directory = sys_get_temp_dir().'/easywi-local-config-'.bin2hex(random_bytes(8));
        mkdir($directory.'/instance/cfg', 0700, true);
        file_put_contents($directory.'/instance/cfg/server.cfg', 'safe');
        [$resolver, $instance] = $this->localFixture($directory.'/instance');
        try {
            self::assertSame(realpath($directory.'/instance/cfg/server.cfg'), $resolver->resolveLocalFile($instance, 'cfg/server.cfg'));
            self::assertNull($resolver->resolveLocalFile($instance, 'cfg/missing.cfg'));
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
        }
    }

    public function testSymlinksCannotExposeFilesOutsideTheInstance(): void
    {
        $directory = sys_get_temp_dir().'/easywi-local-config-'.bin2hex(random_bytes(8));
        mkdir($directory.'/instance', 0700, true);
        file_put_contents($directory.'/secret.cfg', 'outside');
        self::assertTrue(symlink($directory.'/secret.cfg', $directory.'/instance/link.cfg'));
        [$resolver, $instance] = $this->localFixture($directory.'/instance');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('PATH_TRAVERSAL');
            $resolver->resolveLocalFile($instance, 'link.cfg');
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
        }
    }

    public function testNullBytesCannotReachFilesystemFunctions(): void
    {
        [$resolver, $instance] = $this->localFixture('/srv/instances/test');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PATH_TRAVERSAL');
        $resolver->resolveLocalFile($instance, "cfg/server\0.cfg");
    }

    /** @return array{InstanceConfigPathResolver,Instance} */
    private function localFixture(string $root): array
    {
        $settings = $this->createStub(AppSettingsService::class);
        $settings->method('getInstanceBaseDir')->willReturn($root);
        $node = $this->createStub(Agent::class);
        $node->method('getMetadata')->willReturn(['os' => 'linux']);
        $node->method('getLastHeartbeatStats')->willReturn(['os' => 'linux']);
        $instance = $this->createStub(Instance::class);
        $instance->method('getInstallPath')->willReturn($root);
        $instance->method('getNode')->willReturn($node);

        return [new InstanceConfigPathResolver(new InstanceFilesystemResolver($settings)), $instance];
    }

}
