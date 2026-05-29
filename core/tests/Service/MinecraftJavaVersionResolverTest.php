<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Gameserver\Application\MinecraftJavaVersionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MinecraftJavaVersionResolverTest extends TestCase
{
    #[DataProvider('javaVersionCases')]
    public function testResolvesJavaVersionFromMinecraftVersion(?string $mcVersion, string $expected): void
    {
        self::assertSame($expected, (new MinecraftJavaVersionResolver())->resolve($mcVersion));
    }

    public function testConfiguredJavaVersionOverridesAutomaticDetection(): void
    {
        $resolver = new MinecraftJavaVersionResolver();

        self::assertSame('17', $resolver->resolve('1.21.6', '17'));
        self::assertSame('java17', $resolver->javaBin('1.21.6', '17'));
    }

    public static function javaVersionCases(): iterable
    {
        yield ['1.16.5', '8'];
        yield ['1.17.1', '16'];
        yield ['1.18.2', '17'];
        yield ['1.20.4', '17'];
        yield ['1.20.5', '21'];
        yield ['1.21.6', '21'];
        yield [null, '21'];
        yield ['latest', '21'];
    }
}
