<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\Query\InstanceQueryResolver;
use App\Module\Gameserver\Application\Query\InvalidInstanceQueryConfiguration;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use PHPUnit\Framework\TestCase;

final class InstanceQueryResolverTest extends TestCase
{
    public function testResolvesSourceA2sFromSteamTemplateUsingQueryPort(): void
    {
        $instance = $this->createInstance('cs2', 730, [
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
        ]);
        $portBlock = $this->createPortBlock($instance, 27015, 27016);

        $resolver = new InstanceQueryResolver();
        $spec = $resolver->resolve($instance, $portBlock);

        self::assertTrue($spec->isSupported());
        self::assertSame('steam_a2s', $spec->getType());
        self::assertSame(27016, $spec->getPort());
    }

    public function testResolvesMinecraftType(): void
    {
        $instance = $this->createInstance('minecraft_vanilla_all', null, [
            ['name' => 'game', 'protocol' => 'tcp'],
        ]);
        $portBlock = $this->createPortBlock($instance, 25565, 25565);

        $resolver = new InstanceQueryResolver();
        $spec = $resolver->resolve($instance, $portBlock);

        self::assertTrue($spec->isSupported());
        self::assertSame('minecraft_java', $spec->getType());
        self::assertSame(25565, $spec->getPort());
    }

    public function testInvalidPortThrowsException(): void
    {
        $instance = $this->createInstance('cs2', 730, [
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars(['QUERY_PORT' => '99999']);

        $this->expectException(InvalidInstanceQueryConfiguration::class);
        (new InstanceQueryResolver())->resolve($instance, null);
    }

    public function testResolvesLegacyStringQueryTypeAlias(): void
    {
        $instance = $this->createInstance('cs2', 730, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->getTemplate()->setRequirements([
            'query' => 'source',
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27015));

        self::assertTrue($spec->isSupported());
        self::assertSame('steam_a2s', $spec->getType());
    }

    public function testDisablesQueryWhenLegacyFlagIsFalse(): void
    {
        $instance = $this->createInstance('cs2', 730, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->getTemplate()->setRequirements([
            'query' => false,
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27015));

        self::assertFalse($spec->isSupported());
        self::assertNull($spec->getType());
    }


    public function testResolvesLegacyArrayEngineAlias(): void
    {
        $instance = $this->createInstance('cs2', 730, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->getTemplate()->setRequirements([
            'query' => [
                'engine' => 'source-engine',
            ],
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27015));

        self::assertTrue($spec->isSupported());
        self::assertSame('steam_a2s', $spec->getType());
    }

    public function testDisablesQueryWhenLegacyEnabledFlagIsFalse(): void
    {
        $instance = $this->createInstance('cs2', 730, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->getTemplate()->setRequirements([
            'query' => [
                'type' => 'steam_a2s',
                'enabled' => false,
            ],
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27015));

        self::assertFalse($spec->isSupported());
        self::assertNull($spec->getType());
    }

    public function testSteamA2sWithoutDedicatedQueryPortPrefersGamePortOverLegacyQueryPort(): void
    {
        $instance = $this->createInstance('cs2', 730, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'QUERY_PORT' => '27016',
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27015));

        self::assertTrue($spec->isSupported());
        self::assertSame('steam_a2s', $spec->getType());
        self::assertSame(27015, $spec->getPort());
    }

    public function testSteamA2sWithDedicatedQueryPortPrefersQueryPort(): void
    {
        $instance = $this->createInstance('csgo_legacy', 740, [
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'QUERY_PORT' => '27016',
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27016));

        self::assertTrue($spec->isSupported());
        self::assertSame('steam_a2s', $spec->getType());
        self::assertSame(27016, $spec->getPort());
    }


    private function createInstance(string $gameKey, ?int $steamAppId, array $requiredPorts): Instance
    {
        $customer = new User('customer@example.com', UserType::Customer);
        $agent = new Agent('node-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'Node');
        $agent->recordHeartbeat([], '1.0.0', '203.0.113.9');
        $template = new Template(
            $gameKey,
            'Template',
            null,
            $steamAppId,
            null,
            $requiredPorts,
            '',
            [],
            [],
            [],
            [],
            '',
            '',
            [],
            [],
        );

        return new Instance($customer, $template, $agent, 100, 1024, 1024, null, InstanceStatus::Running, InstanceUpdatePolicy::Manual);
    }

    private function createPortBlock(Instance $instance, int $start, int $end): PortBlock
    {
        $pool = new PortPool($instance->getNode(), 'pool', 'GAME_UDP', $start, $end);
        $block = new PortBlock($pool, $instance->getCustomer(), $start, $end);
        $block->assignInstance($instance);

        return $block;
    }
}
