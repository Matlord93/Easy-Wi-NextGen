<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class KernelTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER['EASYWI_CACHE_DIR'], $_ENV['EASYWI_CACHE_DIR']);
        unset($_SERVER['EASYWI_LOG_DIR'], $_ENV['EASYWI_LOG_DIR']);
    }

    public function testKernelClassIsInstantiable(): void
    {
        $kernelClass = self::getKernelClass();

        $kernel = new $kernelClass('test', true);

        self::assertInstanceOf($kernelClass, $kernel);
    }

    public function testCacheDirCanBeConfiguredThroughEnvironment(): void
    {
        $kernelClass = self::getKernelClass();
        $_SERVER['EASYWI_CACHE_DIR'] = '/tmp/easywi-custom-cache';

        $kernel = new $kernelClass('prod', false);

        self::assertSame('/tmp/easywi-custom-cache', $kernel->getCacheDir());
    }

    public function testLogDirCanBeConfiguredThroughEnvironment(): void
    {
        $kernelClass = self::getKernelClass();
        $_SERVER['EASYWI_LOG_DIR'] = '/tmp/easywi-custom-log';

        $kernel = new $kernelClass('prod', false);

        self::assertSame('/tmp/easywi-custom-log', $kernel->getLogDir());
    }
}
