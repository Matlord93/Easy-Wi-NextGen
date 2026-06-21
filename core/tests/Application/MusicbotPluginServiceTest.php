<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Musicbot\Application\PluginConfigService;
use App\Module\Musicbot\Application\PluginRegistryService;
use PHPUnit\Framework\TestCase;

final class MusicbotPluginServiceTest extends TestCase
{
    public function testManifestValidatesIdentifierAndPermissions(): void
    {
        $registry = new PluginRegistryService(sys_get_temp_dir());
        $manifest = $registry->manifestFromArray([
            'identifier' => 'demo.plugin',
            'name' => 'Demo Plugin',
            'version' => '1.0.0',
            'permissions' => ['playback.control', 'queue.manage'],
            'supported_platforms' => ['teamspeak'],
        ]);

        self::assertSame('demo.plugin', $manifest->identifier);
        self::assertSame(['playback.control', 'queue.manage'], $manifest->permissions);
        self::assertSame(['teamspeak'], $manifest->supportedPlatforms);
    }

    public function testManifestRejectsPathTraversalIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PluginRegistryService(sys_get_temp_dir()))->manifestFromArray([
            'identifier' => '../evil',
            'name' => 'Evil',
            'version' => '1.0.0',
        ]);
    }

    public function testConfigServiceFiltersAgainstSchema(): void
    {
        $service = new PluginConfigService();

        self::assertSame([
            'enabled' => true,
            'limit' => 5,
            'label' => 'demo',
        ], $service->filterConfig([
            'enabled' => 'true',
            'limit' => '5',
            'label' => 'demo',
            '../ignored' => 'bad',
        ], [
            'properties' => [
                'enabled' => ['type' => 'boolean'],
                'limit' => ['type' => 'integer'],
                'label' => ['type' => 'string'],
            ],
        ]));
    }
}
