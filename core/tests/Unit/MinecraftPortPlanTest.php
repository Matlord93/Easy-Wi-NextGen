<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\Template;
use App\Module\Gameserver\Application\MinecraftPortPlan;
use PHPUnit\Framework\TestCase;

final class MinecraftPortPlanTest extends TestCase
{
    public function testLegacyTemplatePortsKeepExtensionFieldsAndDefaultProtocol(): void
    {
        $template = $this->createStub(Template::class);
        $template->method('getRequiredPorts')->willReturn([4 => ['name' => 'game', 'count' => 2]]);

        self::assertSame([['name' => 'game', 'count' => 2, 'protocol' => 'udp']], MinecraftPortPlan::definitions($template, []));
    }

    public function testManagedMinecraftPortsMatchRequestedServices(): void
    {
        $template = $this->createStub(Template::class);
        $template->method('getInstallResolver')->willReturn(['type' => 'papermc_paper']);
        $settings = MinecraftPortPlan::settings($template, true, false);
        $ports = MinecraftPortPlan::definitions($template, $settings);

        self::assertSame(['game', 'rcon'], array_column($ports, 'name'));
        self::assertSame(['tcp', 'tcp'], array_column($ports, 'protocol'));
        self::assertNotSame('', $settings['RCON_PASSWORD']);
    }

    public function testBedrockSignalingSharesGamePortAndIsNotDuplicated(): void
    {
        $template = $this->createStub(Template::class);
        $template->method('getInstallResolver')->willReturn(['type' => 'minecraft_bedrock']);
        $reservations = MinecraftPortPlan::withBedrockSignaling($template, [['role' => 'game', 'protocol' => 'udp', 'port' => 19132]]);

        self::assertSame(['role' => 'signaling', 'protocol' => 'tcp', 'port' => 19132], $reservations[1]);
        self::assertSame($reservations, MinecraftPortPlan::withBedrockSignaling($template, $reservations));
    }
}
