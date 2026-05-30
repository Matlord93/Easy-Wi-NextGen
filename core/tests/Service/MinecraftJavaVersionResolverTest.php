<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Gameserver\Application\JavaBinaryConfig;
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

    public function testDefaultBinariesMatchConstants(): void
    {
        $resolver = new MinecraftJavaVersionResolver();

        self::assertSame('java8', $resolver->javaBin('1.16.5'));
        self::assertSame('java17', $resolver->javaBin('1.17.1'));
        self::assertSame('java17', $resolver->javaBin('1.18.2'));
        self::assertSame('java21', $resolver->javaBin('1.21.0'));
    }

    public function testFallbackIsJava21WhenVersionIsNull(): void
    {
        $resolver = new MinecraftJavaVersionResolver();

        self::assertSame('java21', $resolver->javaBin(null));
        self::assertSame('java21', $resolver->javaBin('latest'));
        self::assertSame('java21', $resolver->javaBin(''));
    }

    public function testCustomBinaryPathOverridesDefault(): void
    {
        $config = new JavaBinaryConfig([
            '21' => '/usr/lib/jvm/java-21-openjdk-amd64/bin/java',
            '17' => '/usr/lib/jvm/java-17-openjdk-amd64/bin/java',
        ]);
        $resolver = new MinecraftJavaVersionResolver($config);

        self::assertSame('/usr/lib/jvm/java-21-openjdk-amd64/bin/java', $resolver->javaBin('1.21.0'));
        self::assertSame('/usr/lib/jvm/java-17-openjdk-amd64/bin/java', $resolver->javaBin('1.20.4'));
    }

    public function testUnoverriddenVersionUsesDefault(): void
    {
        $config = new JavaBinaryConfig(['21' => '/custom/java21']);
        $resolver = new MinecraftJavaVersionResolver($config);

        self::assertSame('/custom/java21', $resolver->javaBin('1.21.0'));
        self::assertSame('java17', $resolver->javaBin('1.20.4'));
        self::assertSame('java8', $resolver->javaBin('1.16.5'));
    }

    public function testCommandBasedBinaryNameIsAccepted(): void
    {
        $config = new JavaBinaryConfig(['21' => 'java21', '17' => 'java17']);
        $resolver = new MinecraftJavaVersionResolver($config);

        self::assertSame('java21', $resolver->javaBin('1.21.0'));
        self::assertSame('java17', $resolver->javaBin('1.18.0'));
    }

    public function testAbsolutePathBinaryIsAccepted(): void
    {
        $config = new JavaBinaryConfig(['21' => '/usr/bin/java']);
        $resolver = new MinecraftJavaVersionResolver($config);

        self::assertSame('/usr/bin/java', $resolver->javaBin('1.21.0'));
    }

    public static function javaVersionCases(): iterable
    {
        yield ['1.16.5', '8'];
        yield ['1.17.1', '17'];
        yield ['1.18.2', '17'];
        yield ['1.20.4', '17'];
        yield ['1.20.5', '21'];
        yield ['1.21.6', '21'];
        yield ['1.21.11', '21'];
        yield ['26.1.2', '25'];
        yield ['26.2', '25'];
        yield [null, '21'];
        yield ['latest', '21'];
    }
}
