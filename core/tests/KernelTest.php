<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class KernelTest extends KernelTestCase
{
    public function testKernelClassIsInstantiable(): void
    {
        $kernelClass = self::getKernelClass();

        $kernel = new $kernelClass('test', true);

        self::assertInstanceOf($kernelClass, $kernel);
    }
}
