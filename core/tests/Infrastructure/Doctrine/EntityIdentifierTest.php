<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine;

use App\Infrastructure\Doctrine\EntityIdentifier;
use PHPUnit\Framework\TestCase;

final class EntityIdentifierTest extends TestCase
{
    public function testIdentifierValidationDoesNotCoerceStringKeysOrZero(): void
    {
        self::assertSame('00123', EntityIdentifier::require('00123'));
        self::assertSame(0, EntityIdentifier::require(0));
        self::assertSame(42, EntityIdentifier::require(42));
    }

    public function testTransientEntitiesCannotProduceAnEmptyRouteOrJobTarget(): void
    {
        $this->expectException(\LogicException::class);
        EntityIdentifier::require(null);
    }
}
