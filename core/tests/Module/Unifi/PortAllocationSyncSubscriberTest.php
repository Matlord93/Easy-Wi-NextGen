<?php

declare(strict_types=1);

namespace App\Tests\Module\Unifi;

use App\Message\UnifiSyncInstancePortsMessage;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Ports\Domain\Entity\PortAllocation;
use App\Module\Unifi\EventSubscriber\PortAllocationSyncSubscriber;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PortAllocationSyncSubscriberTest extends TestCase
{
    public function testAllocationChangeQueuesAnInstanceSync(): void
    {
        $instance = $this->createStub(Instance::class);
        $instance->method('getId')->willReturn(42);
        $allocation = (new \ReflectionClass(PortAllocation::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(PortAllocation::class, 'instance'))->setValue($allocation, $instance);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (object $message): bool => $message instanceof UnifiSyncInstancePortsMessage && $message->getInstanceId() === 42))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        (new PortAllocationSyncSubscriber($bus))->postPersist(new LifecycleEventArgs($allocation, $this->createStub(ObjectManager::class)));
    }
}
