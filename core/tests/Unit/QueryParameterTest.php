<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Domain\Entity\User;
use App\Repository\QueryParameter;
use Doctrine\ORM\ORMInvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class QueryParameterTest extends TestCase
{
    public function testNumericAndStringIdentifiersKeepTheirDatabaseTypes(): void
    {
        self::assertSame(42, QueryParameter::entityId(42, User::class));
        self::assertSame('0042', QueryParameter::entityId('0042', User::class));
        self::assertSame(0, QueryParameter::entityId(0, User::class));
    }

    public function testTransientEntitiesStillRaiseTheDoctrineBindingException(): void
    {
        $this->expectException(ORMInvalidArgumentException::class);
        $this->expectExceptionMessage(User::class);
        QueryParameter::entityId(null, User::class);
    }
}
