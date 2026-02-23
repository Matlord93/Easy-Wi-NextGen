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

    public function testPrefersMetadataPublicIpWhenHeartbeatIpIsLoopback(): void
    {
        $instance = $this->createInstance('l4d2', 550, [
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
        ]);
        $instance->getNode()->setMetadata([
            'public_ip' => '203.0.113.9',
        ]);
        $instance->getNode()->recordHeartbeat([], '1.0.0', '127.0.0.1');

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27016));

        self::assertSame('203.0.113.9', $spec->getHost());
        self::assertSame(27015, $spec->getPort());
        self::assertSame('node_ip', $spec->getExtra()['resolved_host_source']);
    }

    public function testThrowsWhenBindAndNodeIpAreMissingInIsolatedNetwork(): void
    {
        $instance = $this->createInstance('cs2', 730, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'NETWORK_MODE' => 'isolated',
        ]);
        $instance->getNode()->setMetadata([]);
        $instance->getNode()->recordHeartbeat([], '1.0.0', '127.0.0.1');

        $this->expectException(InvalidInstanceQueryConfiguration::class);
        $this->expectExceptionMessage('Query host is missing');

        (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27015));
    }

    public function testPrefersSetupVarPublicIpWhenHeartbeatIpIsLoopbackAndMetadataMissing(): void
    {
        $instance = $this->createInstance('l4d2', 550, [
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars([
            'PUBLIC_IP' => '198.51.100.24',
            'GAME_PORT' => '27015',
            'QUERY_PORT' => '27016',
        ]);
        $instance->getNode()->setMetadata([]);
        $instance->getNode()->recordHeartbeat([], '1.0.0', '127.0.0.1');

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27016));

        self::assertSame('198.51.100.24', $spec->getHost());
        self::assertSame(27015, $spec->getPort());
    }

    public function testPrefersServiceBaseUrlHostWhenOtherCandidatesAreLoopback(): void
    {
        $instance = $this->createInstance('l4d2', 550, [
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
        ]);
        $instance->getNode()->setMetadata([]);
        $instance->getNode()->setServiceBaseUrl('https://agent.example.net:7456');
        $instance->getNode()->recordHeartbeat([], '1.0.0', '127.0.0.1');

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27016));

        self::assertSame('agent.example.net', $spec->getHost());
        self::assertSame(27015, $spec->getPort());
    }


    public function testFallsBackToMetadataHostnameWhenNoIpCandidatesExist(): void
    {
        $instance = $this->createInstance('l4d2', 550, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->getNode()->setMetadata([
            'hostname' => 'node-2.internal.lan',
        ]);
        $instance->getNode()->setServiceBaseUrl(null);
        $instance->getNode()->recordHeartbeat([], '1.0.0', '127.0.0.1');
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'NETWORK_MODE' => 'isolated',
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, null);

        self::assertSame('node-2.internal.lan', $spec->getHost());
        self::assertSame('node_ip', $spec->getExtra()['resolved_host_source']);
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

    public function testResolvesSource1AliasToSteamA2s(): void
    {
        $instance = $this->createInstance('css', 240, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->getTemplate()->setRequirements([
            'query' => [
                'type' => 'source1',
            ],
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
        self::assertSame(27016, $spec->getPort());
    }

    public function testSteamA2sWithoutDedicatedQueryPortDefaultsToGamePortForSource1Templates(): void
    {
        $instance = $this->createInstance('css', 240, [
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

    public function testSteamA2sWithoutDedicatedQueryPortUsesPlusOneWhenBehaviorRequestsIt(): void
    {
        $instance = $this->createInstance('css', 240, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'QUERY_PORT' => '27016',
        ]);
        $instance->getTemplate()->setRequirements([
            'query' => [
                'type' => 'steam_a2s',
                'query_port_behavior' => 'PLUS_ONE',
            ],
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27015));

        self::assertSame(27016, $spec->getPort());
    }

    public function testSteamA2sWithoutDedicatedQueryPortUsesExplicitQueryPortWhenBehaviorRequestsIt(): void
    {
        $instance = $this->createInstance('css', 240, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'QUERY_PORT' => '27018',
        ]);
        $instance->getTemplate()->setRequirements([
            'query' => [
                'type' => 'steam_a2s',
                'query_port_behavior' => 'explicit',
            ],
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27015));

        self::assertSame(27018, $spec->getPort());
    }


    public function testTemplateDefaultBehaviorsKeepSource1OnGamePortAndCs2OnQueryPort(): void
    {
        $source1 = $this->createInstance('l4d2', 550, [
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
        ]);
        $source1->getTemplate()->setRequirements([
            'query' => [
                'type' => 'steam_a2s',
                'query_port_behavior' => 'same_as_game_port',
            ],
        ]);
        $source1Spec = (new InstanceQueryResolver())->resolve($source1, $this->createPortBlock($source1, 27015, 27016));

        self::assertSame(27015, $source1Spec->getPort());

        $source2 = $this->createInstance('cs2', 730, [
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
        ]);
        $source2->getTemplate()->setRequirements([
            'query' => [
                'type' => 'steam_a2s',
                'query_port_behavior' => 'explicit',
            ],
        ]);
        $source2Spec = (new InstanceQueryResolver())->resolve($source2, $this->createPortBlock($source2, 27015, 27016));

        self::assertSame(27016, $source2Spec->getPort());
    }

    public function testSteamA2sUsesSvQueryPortWhenProvidedInSetupVars(): void
    {
        $instance = $this->createInstance('l4d2', 550, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'SV_QUERYPORT' => '27020',
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, null);

        self::assertSame(27020, $spec->getPort());
    }

    public function testSteamA2sWithDedicatedQueryPortDefaultsToGamePortForSource1(): void
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
        self::assertSame(27015, $spec->getPort());
    }



    public function testSteamA2sWithDedicatedQueryPortCanUseExplicitBehavior(): void
    {
        $instance = $this->createInstance('csgo_legacy', 740, [
            ['name' => 'game', 'protocol' => 'udp'],
            ['name' => 'query', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'QUERY_PORT' => '27016',
        ]);
        $instance->getTemplate()->setRequirements([
            'query' => [
                'type' => 'steam_a2s',
                'query_port_behavior' => 'explicit',
            ],
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, $this->createPortBlock($instance, 27015, 27016));

        self::assertSame(27016, $spec->getPort());
    }

    public function testSteamA2sFallsBackToLegacyPortSetupVar(): void
    {
        $instance = $this->createInstance('l4d2', 550, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->setSetupVars([
            'PORT' => '27015',
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, null);

        self::assertTrue($spec->isSupported());
        self::assertSame('steam_a2s', $spec->getType());
        self::assertSame(27015, $spec->getPort());
    }

    public function testMinecraftFallsBackToLegacyPortSetupVar(): void
    {
        $instance = $this->createInstance('minecraft_vanilla_all', null, [
            ['name' => 'game', 'protocol' => 'tcp'],
        ]);
        $instance->setSetupVars([
            'PORT' => '25565',
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, null);

        self::assertTrue($spec->isSupported());
        self::assertSame('minecraft_java', $spec->getType());
        self::assertSame(25565, $spec->getPort());
    }


    public function testMissingHostSourcesThrowsDetailedInvalidInstanceHostMessage(): void
    {
        $instance = $this->createInstance('l4d2', 550, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->getNode()->setMetadata([]);
        $instance->getNode()->recordHeartbeat([], '1.0.0', '127.0.0.1');
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'NETWORK_MODE' => 'isolated',
        ]);

        $this->expectException(InvalidInstanceQueryConfiguration::class);
        $this->expectExceptionMessage('Query host is missing for instance');
        (new InstanceQueryResolver())->resolve($instance, null);
    }

    public function testHostNetworkModeFallsBackToLoopbackOnlyInHostMode(): void
    {
        $instance = $this->createInstance('l4d2', 550, [
            ['name' => 'game', 'protocol' => 'udp'],
        ]);
        $instance->getNode()->setMetadata([]);
        $instance->getNode()->recordHeartbeat([], '1.0.0', '127.0.0.1');
        $instance->setSetupVars([
            'GAME_PORT' => '27015',
            'NETWORK_MODE' => 'host',
        ]);

        $spec = (new InstanceQueryResolver())->resolve($instance, null);

        self::assertSame('127.0.0.1', $spec->getHost());
        self::assertSame('loopback', $spec->getExtra()['resolved_host_source']);
        self::assertSame('host', $spec->getExtra()['network_mode']);
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
