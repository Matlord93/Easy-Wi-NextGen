<?php

declare(strict_types=1);

namespace App\Tests\Module\Teamspeak\Application;

use App\Module\Teamspeak\Application\TeamSpeakDockerComposeRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TeamSpeakDockerComposeRendererTest extends TestCase
{
    public function testRendersOfficialImageAndPersistentVolume(): void
    {
        $compose = Yaml::parse((new TeamSpeakDockerComposeRenderer())->render('ts-42', ['voice_udp' => 9988, 'query_tcp' => 10012, 'file_tcp' => 30034], true));
        self::assertSame('teamspeak:latest', $compose['services']['teamspeak']['image']);
        self::assertSame(['9988:9987/udp', '10012:10011/tcp', '30034:30033/tcp'], $compose['services']['teamspeak']['ports']);
        self::assertSame(['ts-42-data:/var/ts3server'], $compose['services']['teamspeak']['volumes']);
        self::assertSame(['.env'], $compose['services']['teamspeak']['env_file']);
    }

    public function testRejectsDuplicatePorts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TeamSpeakDockerComposeRenderer())->render('ts-42', ['voice_udp' => 9988, 'query_tcp' => 9988, 'file_tcp' => 30034], true);
    }

    public function testSecretEnvironmentIsAllowlisted(): void
    {
        self::assertSame("TS3SERVER_SERVERADMIN_PASSWORD=secret\n", (new TeamSpeakDockerComposeRenderer())->renderSecretEnvironment(['TS3SERVER_SERVERADMIN_PASSWORD' => 'secret']));
    }

    public function testRequiresExplicitLicenseAcceptance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TeamSpeakDockerComposeRenderer())->render('ts-42', ['voice_udp' => 9988, 'query_tcp' => 10012, 'file_tcp' => 30034], false);
    }
}
