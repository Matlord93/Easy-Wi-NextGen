<?php

declare(strict_types=1);

namespace App\Tests\Module\Unifi;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Domain\Entity\PortAllocation;
use App\Module\Unifi\Application\UnifiSyncOutbox;
use App\Module\Unifi\Domain\Entity\UnifiSyncRequest;
use App\Module\Unifi\EventSubscriber\PortAllocationSyncSubscriber;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

final class PortAllocationSyncSubscriberTest extends TestCase
{
    public function testAllocationChangeQueuesAnInstanceSyncInTheSameTransaction(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 4).'/src'], true);
        $config->enableNativeLazyObjects(true);
        $em = new EntityManager($connection, $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(UnifiSyncRequest::class)]);
        try {
            $instance = $this->createStub(Instance::class);
            $instance->method('getId')->willReturn(42);
            $allocation = (new \ReflectionClass(PortAllocation::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(PortAllocation::class, 'instance'))->setValue($allocation, $instance);
            $subscriber = new PortAllocationSyncSubscriber(new UnifiSyncOutbox($connection));
            $connection->beginTransaction();
            $subscriber->postPersist(new LifecycleEventArgs($allocation, $this->createStub(ObjectManager::class)));
            self::assertSame(42, (int) $connection->fetchOne('SELECT instance_id FROM unifi_sync_requests'));
            $connection->rollBack();
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM unifi_sync_requests'));
        } finally {
            $connection->close();
        }
    }
}
