<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\Instance;
use PHPUnit\Framework\TestCase;

final class InstanceInstallPathTest extends TestCase
{
    public function testSetInstallPathAcceptsWindowsAbsolutePath(): void
    {
        $instance = (new \ReflectionClass(Instance::class))->newInstanceWithoutConstructor();

        $instance->setInstallPath('C:\\games\\gs11');

        self::assertSame('C:\\games\\gs11', $instance->getInstallPath());
    }

    public function testSetInstallPathRejectsRelativePath(): void
    {
        $instance = (new \ReflectionClass(Instance::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('install_path must be absolute.');

        $instance->setInstallPath('relative/path');
    }
}
