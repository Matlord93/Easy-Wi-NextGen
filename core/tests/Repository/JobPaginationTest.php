<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\JobResult;
use App\Repository\JobRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class JobPaginationTest extends TestCase
{
    public function testPaginationKeepsNewestFirstAndExcludesBackgroundJobsFromPageAndTotal(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Module/Core/Domain/Entity'], true);
        $configuration->enableNativeLazyObjects(true);
        $em = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $configuration,
        );
        (new SchemaTool($em))->createSchema([
            $em->getClassMetadata(Job::class),
            $em->getClassMetadata(JobResult::class),
        ]);

        $older = new Job('instance.start', []);
        $newer = new Job('instance.stop', []);
        $hidden = new Job('instance.query.check', []);
        $createdAt = new \ReflectionProperty(Job::class, 'createdAt');
        foreach ([$older, $newer, $hidden] as $offset => $job) {
            $createdAt->setValue($job, new \DateTimeImmutable('2026-11-13 12:00:0'.$offset));
            $em->persist($job);
        }
        $em->flush();
        $em->clear();
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $repository = new JobRepository($registry);

        $first = $repository->findPaginatedLatest(1, 1);
        self::assertSame(2, $first['total']);
        self::assertCount(1, $first['jobs']);
        self::assertSame($newer->getId(), $first['jobs'][0]->getId());

        $second = $repository->findPaginatedLatest(2, 1);
        self::assertSame(2, $second['total']);
        self::assertCount(1, $second['jobs']);
        self::assertSame($older->getId(), $second['jobs'][0]->getId());

        self::assertSame(['jobs' => [], 'total' => 2], $repository->findPaginatedLatest(3, 1));
    }
}
