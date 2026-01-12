<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class KernelTest extends KernelTestCase
{
    public function testKernelBoots(): void
    {
        self::bootKernel();
        self::assertTrue(self::$kernel->isBooted());
    }
}
