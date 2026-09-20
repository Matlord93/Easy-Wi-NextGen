<?php

declare(strict_types=1);

namespace App\Tests\Module\Gameserver\Application;

use App\Module\Gameserver\Application\DockerGameComposeRenderer;
use App\Module\Gameserver\Application\DockerGameTemplateCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DockerGameComposeRendererTest extends TestCase
{
    public function testCatalogContainsRequestedGames(): void
    {
        self::assertSame(['minecraft', 'fivem', 'cs2', 'rust'], array_keys((new DockerGameTemplateCatalog())->all()));
    }

    public function testRendersManagedMinecraftComposeWithPrivateVolume(): void
    {
        $yaml = (new DockerGameComposeRenderer(new DockerGameTemplateCatalog()))->render('minecraft', 'mc-42', ['game' => 25570]);
        $compose = Yaml::parse($yaml);

        self::assertSame('itzg/minecraft-server:latest', $compose['services']['gameserver']['image']);
        self::assertSame(['25570:25565/tcp'], $compose['services']['gameserver']['ports']);
        self::assertSame('TRUE', $compose['services']['gameserver']['environment']['EULA']);
        self::assertSame(['mc-42-data:/data'], $compose['services']['gameserver']['volumes']);
        self::assertSame('true', $compose['services']['gameserver']['labels']['easywi.managed']);
    }

    public function testCanUseExternalSharedStorageVolume(): void
    {
        $yaml = (new DockerGameComposeRenderer(new DockerGameTemplateCatalog()))->render('rust', 'rust-7', ['game_udp' => 28025, 'query_udp' => 28026, 'rcon_tcp' => 28027], [], true);
        $compose = Yaml::parse($yaml);

        self::assertSame(['easywi-games-shared:/steamcmd/rust'], $compose['services']['gameserver']['volumes']);
        self::assertTrue($compose['volumes']['easywi-games-shared']['external']);
    }

    public function testRejectsUnprivilegedOrMissingPorts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DockerGameComposeRenderer(new DockerGameTemplateCatalog()))->render('cs2', 'cs2-1', ['game_tcp' => 80]);
    }
}
