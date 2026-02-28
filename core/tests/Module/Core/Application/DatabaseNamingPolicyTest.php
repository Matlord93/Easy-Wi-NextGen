<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\DatabaseNamingPolicy;
use PHPUnit\Framework\TestCase;

final class DatabaseNamingPolicyTest extends TestCase
{
    public function testReservedDatabaseNameIsRejected(): void
    {
        $policy = new DatabaseNamingPolicy();

        self::assertNotSame([], $policy->validateDatabaseName('select'));
    }

    public function testReservedUsernameIsRejected(): void
    {
        $policy = new DatabaseNamingPolicy();

        self::assertNotSame([], $policy->validateUsername('database'));
    }

    public function testQuotedIdentifierIsRejected(): void
    {
        $policy = new DatabaseNamingPolicy();

        self::assertNotSame([], $policy->validateDatabaseName('"customerdb"'));
        self::assertNotSame([], $policy->validateUsername('`customer`'));
    }

    public function testReservedWordNormalizationRejectsQuotedReservedWord(): void
    {
        $policy = new DatabaseNamingPolicy();

        self::assertNotSame([], $policy->validateDatabaseName(' [Select] '));
    }
}

