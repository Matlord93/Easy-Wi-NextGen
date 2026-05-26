<?php

declare(strict_types=1);

namespace App\Tests\Teamspeak;

use App\Module\Teamspeak\Application\Update\TeamspeakUpdateLockManager;
use PHPUnit\Framework\TestCase;

final class TeamspeakUpdateLockManagerTest extends TestCase
{
    public function testPreventsParallelLock(): void
    {
        $dir = sys_get_temp_dir().'/ts-lock-'.uniqid('', true);
        $lock = new TeamspeakUpdateLockManager($dir);
        self::assertTrue($lock->acquire('ts6', 7, 60));
        self::assertFalse($lock->acquire('ts6', 7, 60));
        $lock->release('ts6', 7);
        self::assertTrue($lock->acquire('ts6', 7, 60));
    }
}
