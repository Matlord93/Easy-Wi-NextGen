<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Agent;
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
}
