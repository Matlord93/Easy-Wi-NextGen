<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\Scheduler\ScheduleHandlerRegistry;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Ports\Domain\Entity\PortAllocation;
use App\Module\Unifi\Application\UnifiApiClient;
use App\Module\Unifi\Application\UnifiPortSyncService;
use App\Module\Unifi\Application\UnifiRuleDiff;
use App\Module\Unifi\Application\UnifiSettingsProviderInterface;
use App\Module\Unifi\Application\UnifiSyncOutbox;
use App\Module\Unifi\Application\UnifiSyncOutboxWorker;
use App\Module\Unifi\Application\UnifiSyncScheduleHandler;
use App\Module\Unifi\Domain\Entity\UnifiManualRule;
use App\Module\Unifi\Domain\Entity\UnifiPolicy;
use App\Module\Unifi\Domain\Entity\UnifiPortMapping;
use App\Module\Unifi\Domain\Entity\UnifiSettings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UnifiOutboxLifecycleTest extends KernelTestCase
{
    public function testRegisteredAllocationCallbacksCoalesceUpdatesAndRespectRollback(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $em): void {
            [$instance, $node] = $this->instance($em);
            $allocation = new PortAllocation($instance, $node, 'game', 'tcp', 23000, 'first-fit', true);
            $em->persist($allocation);
            $em->flush();
            $connection = $em->getConnection();
            self::assertSame($instance->getId(), (int) $connection->fetchOne('SELECT instance_id FROM unifi_sync_requests'));
            $allocation->setLastCheck(new \DateTimeImmutable(), true);
            $em->flush();
            self::assertSame(2, (int) $connection->fetchOne('SELECT revision FROM unifi_sync_requests'));
            $connection->beginTransaction();
            $allocation->setLastCheck(new \DateTimeImmutable(), false);
            $em->flush();
            self::assertSame(3, (int) $connection->fetchOne('SELECT revision FROM unifi_sync_requests'));
            $connection->rollBack();
            $em->clear();
            self::assertSame(2, (int) $connection->fetchOne('SELECT revision FROM unifi_sync_requests'));
            self::assertInstanceOf(UnifiSyncScheduleHandler::class, self::getContainer()->get(ScheduleHandlerRegistry::class)->get('unifi.ports.sync'));
        });
    }

    public function testCommittedInstanceRemovalCleansOnlyItsRemoteRulesAfterward(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $em): void {
            [$instance, $node] = $this->instance($em);
            $id = $instance->getId();
            self::assertNotNull($id);
            $allocation = new PortAllocation($instance, $node, 'game', 'tcp', 23000, 'first-fit', true);
            $em->persist($allocation);
            $em->flush();
            // No manual allocation removal: the real FK cascades when the instance is removed.
            $em->clear();
            $removed = $em->find(Instance::class, $id);
            self::assertInstanceOf(Instance::class, $removed);
            $em->remove($removed);
            $em->flush();
            self::assertNull($removed->getId());
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM port_allocations'));
            self::assertSame($id, (int) $em->getConnection()->fetchOne('SELECT instance_id FROM unifi_sync_requests'));
            self::assertSame(2, (int) $em->getConnection()->fetchOne('SELECT revision FROM unifi_sync_requests'));
            $calls = [];
            $crypto = new EncryptionService('test', 'test:'.base64_encode(random_bytes(32)));
            $settings = new UnifiSettings();
            $settings->setEnabled(true);
            $settings->setBaseUrl('https://unifi.example.test');
            $settings->setUsername('synthetic-user');
            $settings->setPasswordEncrypted($crypto->encrypt('synthetic-password'));
            $provider = $this->createStub(UnifiSettingsProviderInterface::class);
            $provider->method('getSettings')->willReturn($settings);
            $http = new MockHttpClient(static function (string $method, string $url) use (&$calls, $id): MockResponse {
                $calls[] = [$method, $url];
                if (str_ends_with($url, '/api/auth/login')) {
                    return new MockResponse('{}', ['response_headers' => ['set-cookie: TOKEN=synthetic']]);
                }
                if ($method === 'GET') {
                    return new MockResponse(json_encode(['data' => [
                        ['_id' => 'target-rule', 'name' => 'PANEL-'.$id.'-tcp-23000'],
                        ['_id' => 'other-rule', 'name' => 'PANEL-'.($id + 100).'-tcp-24000'],
                        ['_id' => 'manual-rule', 'name' => 'MANUAL-1-tcp-8080'],
                        ['_id' => 'foreign-rule', 'name' => 'independent-user-rule'],
                    ]], JSON_THROW_ON_ERROR));
                }
                return new MockResponse('{}');
            });
            $sync = new UnifiPortSyncService($provider, $em->getRepository(UnifiPolicy::class), $em->getRepository(UnifiManualRule::class), $em->getRepository(UnifiPortMapping::class), $em->getRepository(PortAllocation::class), $em->getRepository(Instance::class), $crypto, new UnifiApiClient($http), new UnifiRuleDiff(), $em);
            self::assertSame([], $calls, 'A committed allocation deletion must not call remote APIs inline.');
            $worker = new UnifiSyncOutboxWorker(new UnifiSyncOutbox($em->getConnection()), $sync, new NullLogger());
            self::assertSame(['attempted' => 1, 'succeeded' => 1, 'failed' => 0, 'skipped' => 0], $worker->drain());
            $deletes = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'DELETE'));
            self::assertCount(1, $deletes);
            self::assertStringEndsWith('/target-rule', $deletes[0][1]);
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM unifi_sync_requests'));
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM unifi_audit_log'));
        });
    }

    /** @return array{Instance,Agent} */
    private function instance(EntityManagerInterface $em): array
    {
        $customer = new User('unifi-lifecycle@example.test', UserType::Customer);
        $customer->setPasswordHash('synthetic-hash');
        $node = new Agent('unifi-lifecycle', []);
        $template = new Template('minecraft', 'Minecraft', null, null, null, [], '', [], [], [], [], '', '', [], []);
        foreach ([$customer, $node, $template] as $entity) { $em->persist($entity); }
        $em->flush();
        $instance = new Instance($customer, $template, $node, 100, 512, 1024, null, InstanceStatus::Stopped);
        $em->persist($instance);
        $em->flush();
        return [$instance, $node];
    }

    private function withEntityManager(callable $test): void
    {
        $previous = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::ensureKernelShutdown();
        try {
            self::bootKernel();
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $test($em);
        } finally {
            self::ensureKernelShutdown();
            foreach (['SERVER', 'ENV'] as $index => $global) {
                if ($previous[$index] === null) { unset($GLOBALS['_'.$global]['DATABASE_URL']); }
                else { $GLOBALS['_'.$global]['DATABASE_URL'] = $previous[$index]; }
            }
        }
    }
}
