<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\EntityQueryResult;
use Doctrine\ORM\AbstractQuery;
use PHPUnit\Framework\TestCase;

final class EntityQueryResultTest extends TestCase
{
    public function testEntityResultsAreNormalizedToAList(): void
    {
        $entity = new \stdClass();
        $query = $this->createStub(AbstractQuery::class);
        $query->method('getResult')->willReturn(['key' => $entity]);

        self::assertSame([$entity], EntityQueryResult::all($query, \stdClass::class));
    }

    public function testUnexpectedEntityTypeIsRejected(): void
    {
        $query = $this->createStub(AbstractQuery::class);
        $query->method('getResult')->willReturn([['id' => 1]]);

        $this->expectException(\UnexpectedValueException::class);
        EntityQueryResult::all($query, \stdClass::class);
    }

    public function testUnexpectedResultShapeIsRejected(): void
    {
        $query = $this->createStub(AbstractQuery::class);
        $query->method('getResult')->willReturn(1);

        $this->expectException(\UnexpectedValueException::class);
        EntityQueryResult::all($query, \stdClass::class);
    }
    public function testSingleResultPreservesEntityIdentity(): void
    {
        $entity = new \stdClass();
        $query = $this->createStub(AbstractQuery::class);
        $query->method('getOneOrNullResult')->willReturn($entity);

        self::assertSame($entity, EntityQueryResult::one($query, \stdClass::class));
    }

    public function testMissingSingleResultRemainsNull(): void
    {
        $query = $this->createStub(AbstractQuery::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        self::assertNull(EntityQueryResult::one($query, \stdClass::class));
    }

    public function testSingleScalarResultCannotBeMistakenForAnEntity(): void
    {
        $query = $this->createStub(AbstractQuery::class);
        $query->method('getOneOrNullResult')->willReturn(['id' => 1]);

        $this->expectException(\UnexpectedValueException::class);
        EntityQueryResult::one($query, \stdClass::class);
    }

    public function testMutationCountsAcceptNumericDatabaseResults(): void
    {
        $query = $this->createStub(AbstractQuery::class);
        $query->method('execute')->willReturn('12');

        self::assertSame(12, EntityQueryResult::affected($query));
    }

    public function testMutationQueriesCannotReturnHydratedRows(): void
    {
        $query = $this->createStub(AbstractQuery::class);
        $query->method('execute')->willReturn([new \stdClass()]);

        $this->expectException(\UnexpectedValueException::class);
        EntityQueryResult::affected($query);
    }

}
