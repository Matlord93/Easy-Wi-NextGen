<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\Mailbox;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Domain\Event\JobStateChangedEvent;
use App\Module\Core\Domain\Event\ResourceEvent;
use App\Module\Ports\Domain\Entity\PortBlock;
use App\Module\Ports\Domain\Entity\PortPool;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DoctrineLifecycleListenersTest extends KernelTestCase
{
    public function testResourceEventsArePublishedByRegisteredDoctrineListeners(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $em): void {
            $events = [];
            $dispatcher = self::getContainer()->get('event_dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            foreach (['resource.created', 'resource.updated', 'resource.deleted'] as $name) {
                $dispatcher->addListener($name, static function (ResourceEvent $event) use (&$events): void {
                    $events[] = [$event->getName(), $event->getPayload()];
                });
            }
            $customer = $this->customer();
            $domain = new Domain($customer, null, 'lifecycle.example.test');
            $em->persist($customer);
            $em->persist($domain);
            $em->flush();
            $id = (string) $domain->getId();
            $domain->setStatus('active');
            $em->flush();
            $em->remove($domain);
            $em->flush();
            self::assertNull($domain->getId(), 'Doctrine clears generated IDs before postRemove callbacks.');
            self::assertSame(['resource.created', 'resource.updated', 'resource.deleted'], array_column($events, 0));
            foreach ($events as [, $payload]) {
                self::assertSame('Domain', $payload['resource_type']);
                self::assertSame($id, $payload['resource_id']);
            }
        });
    }

    public function testJobStateChangesArePublishedFromAnActualFlush(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $em): void {
            $events = [];
            self::getContainer()->get('event_dispatcher')->addListener('job.state_changed', static function (JobStateChangedEvent $event) use (&$events): void {
                $events[] = $event->getPayload();
            });
            $job = new Job('test.lifecycle', []);
            $em->persist($job);
            $em->flush();
            $job->transitionTo(JobStatus::Running);
            $em->flush();
            self::assertCount(1, $events);
            self::assertSame($job->getId(), $events[0]['job_id']);
            self::assertSame('queued', $events[0]['previous_status']);
            self::assertSame('running', $events[0]['current_status']);
        });
    }

    public function testNewMailboxesCreateDistinctProjectionsAndUpdatesStaySynchronized(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $em): void {
            $customer = $this->customer();
            $domain = new Domain($customer, null, 'mail.example.test');
            $first = new Mailbox($domain, 'first', 'first-hash', [], 100);
            $second = new Mailbox($domain, 'second', 'second-hash', [], 200);
            foreach ([$customer, $domain, $first, $second] as $entity) {
                $em->persist($entity);
            }
            self::assertNull($first->getId());
            self::assertNull($second->getId());
            $em->flush();
            $rows = $em->getConnection()->fetchAllAssociative('SELECT address, mailbox_id, password_hash, quota_mb, enabled FROM mail_users ORDER BY address');
            self::assertCount(2, $rows);
            self::assertSame(['first@mail.example.test', 'second@mail.example.test'], array_column($rows, 'address'));
            self::assertSame([$first->getId(), $second->getId()], array_map('intval', array_column($rows, 'mailbox_id')));
            $first->setQuota(250);
            $first->setEnabled(false);
            $first->setPassword('rotated-hash', []);
            $em->flush();
            $rows = $em->getConnection()->fetchAllAssociative('SELECT password_hash, quota_mb, enabled FROM mail_users ORDER BY address');
            self::assertCount(2, $rows);
            self::assertSame('rotated-hash', $rows[0]['password_hash']);
            self::assertSame(250, (int) $rows[0]['quota_mb']);
            self::assertSame(0, (int) $rows[0]['enabled']);
            self::assertSame('second-hash', $rows[1]['password_hash']);
            self::assertSame(200, (int) $rows[1]['quota_mb']);
            self::assertSame(1, (int) $rows[1]['enabled']);
        });
    }

    public function testRemovingAnInstanceRemovesItsOwnedPortBlockAndAuditsIt(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $em): void {
            $customer = $this->customer();
            $node = new Agent('lifecycle-node', []);
            $template = new Template('minecraft', 'Minecraft', null, null, null, [], '', [], [], [], [], '', '', [], []);
            $pool = new PortPool($node, 'Lifecycle', 'lifecycle', 20000, 20009);
            $block = new PortBlock($pool, $customer, 20000, 20009);
            foreach ([$customer, $node, $template, $pool, $block] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $instance = new Instance($customer, $template, $node, 100, 512, 1024, $block->getId(), InstanceStatus::Stopped);
            $block->assignInstance($instance);
            $em->persist($instance);
            $em->flush();
            $blockId = $block->getId();
            $em->remove($instance);
            $em->flush();
            $em->clear();
            self::assertNull($em->find(PortBlock::class, $blockId));
            $audit = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'port_block.released']);
            self::assertInstanceOf(AuditLog::class, $audit);
            self::assertSame($blockId, $audit->getPayload()['port_block_id']);
        });
    }

    public function testRemovingAnInstancePreservesABlockAssignedToAnotherInstance(): void
    {
        $this->withEntityManager(function (EntityManagerInterface $em): void {
            $customer = $this->customer();
            $node = new Agent('lifecycle-node', []);
            $template = new Template('minecraft', 'Minecraft', null, null, null, [], '', [], [], [], [], '', '', [], []);
            $pool = new PortPool($node, 'Lifecycle', 'lifecycle', 20000, 20009);
            $block = new PortBlock($pool, $customer, 20000, 20009);
            foreach ([$customer, $node, $template, $pool, $block] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $removed = new Instance($customer, $template, $node, 100, 512, 1024, $block->getId(), InstanceStatus::Stopped);
            $owner = new Instance($customer, $template, $node, 100, 512, 1024, $block->getId(), InstanceStatus::Stopped);
            $block->assignInstance($owner);
            $em->persist($removed);
            $em->persist($owner);
            $em->flush();
            $blockId = $block->getId();
            $ownerId = $owner->getId();
            $em->remove($removed);
            $em->flush();
            $em->clear();

            $retained = $em->find(PortBlock::class, $blockId);
            self::assertInstanceOf(PortBlock::class, $retained);
            self::assertSame($ownerId, $retained->getInstance()?->getId());
            self::assertSame(0, $em->getRepository(AuditLog::class)->count(['action' => 'port_block.released']));
        });
    }

    private function customer(): User
    {
        $customer = new User('lifecycle@example.test', UserType::Customer);
        $customer->setPasswordHash('test-password-hash');

        return $customer;
    }

    private function withEntityManager(callable $test): void
    {
        $previous = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::ensureKernelShutdown();
        try {
            self::bootKernel();
            $em = self::getContainer()->get(EntityManagerInterface::class);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $test($em);
        } finally {
            self::ensureKernelShutdown();
            foreach (['SERVER', 'ENV'] as $index => $global) {
                if ($previous[$index] === null) {
                    unset($GLOBALS['_'.$global]['DATABASE_URL']);
                } else {
                    $GLOBALS['_'.$global]['DATABASE_URL'] = $previous[$index];
                }
            }
        }
    }
}
