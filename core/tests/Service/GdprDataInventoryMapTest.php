<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\GdprDataInventoryMap;
use PHPUnit\Framework\TestCase;

final class GdprDataInventoryMapTest extends TestCase
{
    public function testInventoryContainsCorePiiEntities(): void
    {
        $map = new GdprDataInventoryMap();
        $entities = array_column($map->all(), 'entity');

        self::assertContains('users', $entities);
        self::assertContains('customer_profiles', $entities);
        self::assertContains('invoices + payments', $entities);
    }
}
