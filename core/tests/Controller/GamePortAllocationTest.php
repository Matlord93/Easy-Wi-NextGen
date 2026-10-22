<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\Ports\Application\GamePortPoolPolicy;
use App\Module\Ports\Application\NodePortAvailability;
use App\Module\Ports\Application\PortCheckUnavailable;
use App\Module\Ports\Application\PortLeaseManager;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use Doctrine\ORM\EntityManagerInterface;

final class GamePortAllocationTest extends AbstractWebTestCase
{
    private function fixture(): array
    {
        $this->seedSite();
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('DELETE FROM port_allocations');
        $em->getConnection()->executeStatement('DELETE FROM port_blocks');
        $em->getConnection()->executeStatement('DELETE FROM port_pools');
        $instance->getTemplate()->setInstallResolver(['type' => 'minecraft_vanilla']);
        $pool = new PortPool($instance->getNode(), 'Minecraft', 'MC', 32000, 32020);
        $pool->setGameScope('minecraft');
        $em->persist($pool);
        $em->flush();

        return [$instance, $pool, $em];
    }

    private function manager(EntityManagerInterface $em, callable $reply): PortLeaseManager
    {
        $client = $this->createStub(AgentGameServerClient::class);
        $client->method('checkFreePortsForNode')->willReturnCallback($reply);

        return new PortLeaseManager($em->getRepository(PortBlock::class), new NodePortAvailability($client), $em);
    }

    public function testDedicatedPoolsNeverSpillIntoAnotherGameOrGeneralPool(): void
    {
        [$instance,$pool] = $this->fixture();
        $general = new PortPool($instance->getNode(), 'General', 'G', 30000, 30100);
        $other = new PortPool($instance->getNode(), 'Other', 'O', 33000, 33100);
        $other->setGameScope('other');
        self::assertSame([$pool], GamePortPoolPolicy::select([$general, $other, $pool], $instance->getTemplate()));
        $pool->setEnabled(false);
        self::assertSame([], GamePortPoolPolicy::select([$general, $other, $pool], $instance->getTemplate()));
        $instance->getTemplate()->setInstallResolver(['type' => 'steam']);
        self::assertSame([$other], GamePortPoolPolicy::select([$general, $other, $pool], $instance->getTemplate()));
        $specific = new PortPool($instance->getNode(), 'Specific', 'S', 34000, 34100);
        $specific->setGameScope('game:'.$instance->getTemplate()->getGameKey());
        self::assertSame([$specific], GamePortPoolPolicy::select([$general, $other, $specific], $instance->getTemplate()));
    }

    public function testOccupiedNodePortsAndOverlappingPoolsAreSkipped(): void
    {
        [$instance,$pool,$em] = $this->fixture();
        $overlap = new PortPool($instance->getNode(), 'Overlapping', 'OLD', 32000, 32002);
        $em->persist($overlap);
        $em->persist(new PortBlock($overlap, $instance->getCustomer(), 32000, 32001));
        $em->flush();
        $manager = $this->manager($em, static fn ($node, array $checks): array => array_map(static fn (array $c): array => $c + ['free' => !('udp' === $c['proto'] && 32002 === $c['port'])], $checks));
        $block = $manager->allocateBlock($pool, $instance->getCustomer(), 2);
        self::assertSame(32003, $block->getStartPort());
        self::assertSame(32004, $block->getEndPort());
        self::assertNotNull($em->find(PortBlock::class, $block->getId()));
        $next = $manager->allocateBlock($pool, $instance->getCustomer(), 1);
        self::assertSame(32005, $next->getStartPort());
    }

    public function testOfflineOrIncompleteNodeDoesNotAllocateAnything(): void
    {
        [$instance,$pool,$em] = $this->fixture();
        foreach ([static fn () => [], static fn () => throw new \RuntimeException('offline')] as $reply) {
            $manager = $this->manager($em, $reply);
            try {
                $manager->allocateBlock($pool, $instance->getCustomer(), 1);
                self::fail('Allocated unverified port');
            } catch (PortCheckUnavailable) {
                self::assertSame(0, $em->getRepository(PortBlock::class)->count([]));
            }
        }
    }

    public function testManualBlockMustBeFreeAndMatchTheGame(): void
    {
        [$instance,$pool,$em] = $this->fixture();
        $manager = $this->manager($em, static fn ($node, array $checks): array => array_map(static fn (array $c): array => $c + ['free' => false], $checks));
        try {
            $manager->allocateBlocksInRange($pool, $instance->getCustomer(), 32000, 32001, 2);
            self::fail('Allocated occupied manual ports');
        } catch (\RuntimeException) {
            self::assertSame(0, $em->getRepository(PortBlock::class)->count([]));
        }
        $pool->setGameScope('other');
        $em->flush();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gewählten Spiel');
        $manager->assertAssignable(new PortBlock($pool, $instance->getCustomer(), 32000, 32001), $instance->getTemplate());
    }

    public function testCreationUsesMinecraftPoolSkipsBusyPortsAndRollsBackWhenNodeIsOffline(): void
    {
        [$instance,$pool,$em] = $this->fixture();
        $other = new PortPool($instance->getNode(), 'Other first', 'OTHER', 31000, 31020);
        $other->setGameScope('other');
        $em->persist($other);
        $entry = new \App\Module\Core\Domain\Entity\MinecraftVersionCatalog('vanilla', '1.20.6', null, 'https://piston-data.mojang.com/test.jar');
        $entry->setJavaVersion('21');
        $em->persist($entry);
        $em->flush();
        $input = ['customer_id' => $instance->getCustomer()->getId(), 'template_id' => $instance->getTemplate()->getId(), 'node_id' => $instance->getNode()->getId(), 'cpu_limit' => 50, 'ram_limit' => 2048, 'disk_limit' => 10000, 'minecraft_version' => '1.20.6', 'instance_base_dir' => '/home'];
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginPortAdmin($client);
        $client->disableReboot();
        $offline = false;
        $agent = $this->createStub(AgentGameServerClient::class);
        $agent->method('checkFreePortsForNode')->willReturnCallback(static function ($node, array $checks) use (&$offline): array {
            if ($offline) {
                throw new \RuntimeException('node offline');
            }

            return array_map(static fn (array $c): array => $c + ['free' => 32000 !== $c['port']], $checks);
        });
        self::getContainer()->set(NodePortAvailability::class, new NodePortAvailability($agent));
        $client->jsonRequest('POST', '/api/admin/instances', $input);
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true)['id'];
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $created = $em->find(Instance::class, $id);
        $block = $em->find(PortBlock::class, $created->getPortBlockId());
        self::assertSame(32001, $block->getStartPort());
        self::assertSame('minecraft', $block->getPool()->getGameScope());
        $before = $em->getRepository(PortBlock::class)->count([]);
        $offline = true;
        $client->jsonRequest('POST', '/api/admin/instances', $input);
        self::assertResponseStatusCodeSame(503);
        self::assertStringContainsString('Portprüfung', json_decode($client->getResponse()->getContent(), true)['error']);
        // Query the connection directly: rollback deliberately closes the request's ORM manager.
        self::assertSame($before, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM port_blocks'));
    }

    public function testPoolScopeIsConfigurableAndVisibleInTheAdminPanel(): void
    {
        [$instance] = $this->fixture();
        $nodeId = $instance->getNode()->getId();
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginPortAdmin($client);
        $client->jsonRequest('POST', '/api/admin/port-pools', ['node_id' => $nodeId, 'name' => 'Separate Minecraft', 'tag' => 'MINECRAFT', 'start_port' => 35000, 'end_port' => 35100, 'game_scope' => 'minecraft']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('minecraft', json_decode($client->getResponse()->getContent(), true)['game_scope']);
        $poolId = json_decode($client->getResponse()->getContent(), true)['id'];
        $client->jsonRequest('PATCH', '/api/admin/port-pools/'.$poolId, ['game_scope' => 'other']);
        self::assertResponseIsSuccessful();
        self::assertSame('other', json_decode($client->getResponse()->getContent(), true)['game_scope']);
        $crawler = $client->request('GET', '/admin/port-pools');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('name="game_scope"', $client->getResponse()->getContent());
        self::assertStringContainsString('value="minecraft"', $client->getResponse()->getContent());
        $form = $crawler->filterXPath('//form[@action="/admin/port-pools/'.$poolId.'/scope"]');
        $token = $form->filterXPath('.//input[@name="_token"]')->attr('value');
        $client->request('POST', '/admin/port-pools/'.$poolId.'/scope', ['game_scope' => 'minecraft', '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/admin/port-pools/'.$poolId.'/scope', ['game_scope' => 'minecraft', '_token' => $token]);
        self::assertResponseRedirects('/admin/port-pools');
        $client->jsonRequest('POST', '/api/admin/port-pools', ['node_id' => $nodeId, 'name' => 'Invalid', 'tag' => 'BAD', 'start_port' => 35200, 'end_port' => 35300, 'game_scope' => 'unsupported']);
        self::assertResponseStatusCodeSame(400);
    }

    private function loginPortAdmin(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        // Keep these allocation tests independent of the real login throttle
        // shared by functional requests to the same test administrator.
        $ip = self::getContainer()->get('limiter.public_login_ip')->create('127.0.0.1');
        $identifier = self::getContainer()->get('limiter.public_login_identifier')->create('admin+forum@example.test');
        $ip->reset();
        $identifier->reset();
        $this->loginAsRole($client, 'admin');
        $ip->reset();
        $identifier->reset();
    }
}
