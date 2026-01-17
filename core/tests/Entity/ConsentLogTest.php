<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\ConsentLog;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\ConsentType;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class ConsentLogTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $user = new User('customer@example.test', UserType::Customer);
        $log = new ConsentLog($user, ConsentType::Terms, '203.0.113.10', 'Mozilla/5.0', 'v1.2');

        self::assertSame($user, $log->getUser());
        self::assertSame(ConsentType::Terms, $log->getType());
        self::assertSame('203.0.113.10', $log->getIp());
        self::assertSame('Mozilla/5.0', $log->getUserAgent());
        self::assertSame('v1.2', $log->getVersion());
        self::assertInstanceOf(\DateTimeImmutable::class, $log->getAcceptedAt());
    }
}
