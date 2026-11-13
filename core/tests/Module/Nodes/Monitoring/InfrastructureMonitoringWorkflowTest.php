<?php

declare(strict_types=1);

namespace App\Tests\Module\Nodes\Monitoring;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Nodes\Application\Monitoring\InfrastructureCollector;
use App\Module\Nodes\Application\Monitoring\InfrastructureMonitoringSettings;
use App\Module\Nodes\Application\Proxmox\ProxmoxGuestAgent;
use App\Module\Nodes\Application\Proxmox\ProxmoxManager;
use App\Module\Nodes\Application\Proxmox\ProxmoxRrdUsage;
use App\Module\Nodes\Domain\Entity\ProxmoxCluster;
use App\Module\Nodes\Domain\Proxmox\ProxmoxConnection;
use App\Tests\Support\ProxmoxFixture;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class InfrastructureMonitoringWorkflowTest extends KernelTestCase
{
    public function testCollectorPersistsWithoutAnOpenBrowserAndSchedulerIsRegistered(): void
    {
        $before = [];
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/infra-monitor-test-no-config.json'] as $key => $value) {
            $before[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::ensureKernelShutdown();
        self::bootKernel();
        try {
            $c = self::getContainer();
            $em = $c->get(EntityManagerInterface::class);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $node = new Agent('collector-node', ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c']);
            $node->recordHeartbeat(['metrics' => ['collected_at' => gmdate(DATE_ATOM), 'cpu' => ['percent' => 20], 'memory' => ['used' => 20, 'total' => 100]]], 'test', '127.0.0.1', [], ['kvm_managed' => ['supported' => true, 'version' => 1]], 'active');
            $em->persist($node);
            $crypto = $c->get(SecretsCrypto::class);
            $cluster = new ProxmoxCluster('Monitoring fixture', new ProxmoxConnection('https://pve.example.test:8006', 'panel@pve!test', 'test-secret'), $crypto);
            $em->persist($cluster);
            $em->flush();
            $fixture = new ProxmoxFixture();
            $api = $fixture->client();
            $manager = new ProxmoxManager($api, $crypto, $em, $c->get(AuditLogger::class), new ProxmoxGuestAgent($api, new ArrayAdapter()), new ProxmoxRrdUsage($api, new ArrayAdapter()));
            $settings = $c->get(InfrastructureMonitoringSettings::class);
            $settings->save($settings->get() + ['enabled' => true]);
            $collector = new InfrastructureCollector($em, $c->get(\App\Module\Nodes\Application\Monitoring\InfrastructureHistoryStore::class), $c->get(\App\Module\Nodes\Application\Monitoring\InfrastructureAlerts::class), $c->get(\App\Module\Nodes\Application\Monitoring\InfrastructureTargets::class), new InfrastructureUsage(), $c->get(\App\Module\Nodes\Application\Kvm\KvmPanelService::class), $manager, $settings, new LockFactory(new InMemoryStore()));
            $first = $collector->run(true);
            self::assertGreaterThanOrEqual(3, $first['samples']);
            $count = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM infrastructure_samples');
            self::assertSame(0, $collector->run(true)['samples']);
            self::assertSame($count, $em->getConnection()->fetchOne('SELECT COUNT(*) FROM infrastructure_samples'));
            self::assertTrue($collector->run()['skipped']);
            self::assertNotNull($c->get(\App\Module\Core\Application\Scheduler\ScheduleHandlerRegistry::class)->get('infrastructure.monitoring'));
            self::assertSame(['pve-a', 'pve-b'], $settings->state()['known_nodes'][$cluster->getId()]);
            // A lost cluster API must still alarm on previously discovered nodes.
            $thisNode = $c->get(\App\Module\Nodes\Application\Monitoring\InfrastructureTargets::class)->proxmox($cluster, 'node', 'pve-a');
            $fixture->fail = true;
            $now = new \DateTimeImmutable();
            $collector->run(true, $now->modify('+60 seconds'));
            $collector->run(true, $now->modify('+240 seconds'));
            self::assertTrue($c->get(\App\Module\Nodes\Application\Monitoring\InfrastructureAlerts::class)->policy($thisNode)['state']['offline']['active']);
            $fixture->fail = false;
            $fixture->nodes[0]['status'] = 'offline';
            $collector->run(true, $now->modify('+300 seconds'));
            $guestTarget = $c->get(\App\Module\Nodes\Application\Monitoring\InfrastructureTargets::class)->proxmox($cluster, 'qemu', '101');
            self::assertNotNull($c->get(\App\Module\Nodes\Application\Monitoring\InfrastructureAlerts::class)->policy($guestTarget)['state']['offline']['since']);
        } finally {
            self::ensureKernelShutdown();
            foreach ($before as $key => [$server,$env]) {
                if (null === $server) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                } if (null === $env) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
            }
        }
    }
}
