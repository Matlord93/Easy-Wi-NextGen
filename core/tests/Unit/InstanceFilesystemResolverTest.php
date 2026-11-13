<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class InstanceFilesystemResolverTest extends TestCase
{
    public function testResolveInstanceDirUsesWindowsSeparatorWhenBaseDirIsWindowsPath(): void
    {
        $settings = $this->createStub(AppSettingsService::class);
        $resolver = new InstanceFilesystemResolver($settings);

        $instance = $this->createStub(Instance::class);
        $instance->method('getInstanceBaseDir')->willReturn('C:\\gameservers');
        $instance->method('getId')->willReturn(11);

        $customer = $this->createStub(User::class);
        $customer->method('getId')->willReturn(5);
        $instance->method('getCustomer')->willReturn($customer);

        self::assertSame('C:\\gameservers\\gs511', $resolver->resolveInstanceDir($instance));
    }
}
