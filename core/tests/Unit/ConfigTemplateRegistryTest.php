<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Gameserver\Application\ConfigTemplateRegistry;
use PHPUnit\Framework\TestCase;

final class ConfigTemplateRegistryTest extends TestCase
{
    public function testSourceTemplateIncludesServerCfgTarget(): void
    {
        $registry = new ConfigTemplateRegistry();

        $template = $this->createMock(Template::class);
        $template->method('getGameKey')->willReturn('l4d2');
        $template->method('getSupportedOs')->willReturn(['linux']);
        $template->method('getConfigFiles')->willReturn([
            ['path' => 'left4dead2/cfg/server.cfg', 'description' => 'Server cfg'],
        ]);

        $instance = $this->createMock(Instance::class);
        $instance->method('getTemplate')->willReturn($template);

        $targets = $registry->listTargetsForInstance($instance);
        self::assertNotEmpty($targets);
        self::assertSame('merge_kv', $targets[0]['apply_mode']);
        self::assertSame('source', $targets[0]['engine_family']);
    }

    public function testMinecraftTemplateIncludesServerPropertiesTarget(): void
    {
        $registry = new ConfigTemplateRegistry();

        $template = $this->createMock(Template::class);
        $template->method('getGameKey')->willReturn('minecraft_vanilla_all');
        $template->method('getSupportedOs')->willReturn(['linux', 'windows']);
        $template->method('getConfigFiles')->willReturn([
            ['path' => 'server.properties', 'description' => 'Main config'],
        ]);

        $instance = $this->createMock(Instance::class);
        $instance->method('getTemplate')->willReturn($template);

        $targets = $registry->listTargetsForInstance($instance);
        self::assertNotEmpty($targets);
        self::assertSame('properties', $targets[0]['apply_mode']);
        self::assertSame('minecraft_java', $targets[0]['engine_family']);
    }
}
