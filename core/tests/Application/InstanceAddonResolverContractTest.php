<?php

declare(strict_types=1);

namespace App\Tests\Application;

use PHPUnit\Framework\TestCase;

final class InstanceAddonResolverContractTest extends TestCase
{
    public function testResolverFiltersByInstanceTemplate(): void
    {
        $resolver = file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/InstanceAddonResolver.php');
        self::assertIsString($resolver);

        self::assertStringContainsString("findByTemplateGameKey(\$instance->getTemplate())", $resolver);
        self::assertStringContainsString('findAddonForInstance', $resolver);
        self::assertStringContainsString('getTemplate()->getGameKey() !== $instance->getTemplate()->getGameKey()', $resolver);
    }

    public function testResolverComputesInstalledAndUpdateFlagsPerInstance(): void
    {
        $resolver = file_get_contents(__DIR__.'/../../src/Module/Gameserver/Application/InstanceAddonResolver.php');
        self::assertIsString($resolver);

        self::assertStringContainsString("getConfigOverrides()['addons']", $resolver);
        self::assertStringContainsString("'installed' => \$installed", $resolver);
        self::assertStringContainsString("'update_available' => \$installed && \$installedVersion !== \$plugin->getVersion()", $resolver);
    }
}
