<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Service\AuditLogHasher;
use PHPUnit\Framework\TestCase;

final class AuditLogHasherTest extends TestCase
{
    public function testComputesDifferentHashesWithDifferentPreviousValues(): void
    {
        $auditLog = new AuditLog(null, 'user.created', ['email' => 'admin@example.test']);
        $hasher = new AuditLogHasher();

        $hashA = $hasher->compute(null, $auditLog);
        $hashB = $hasher->compute('previous-hash', $auditLog);

        self::assertNotSame($hashA, $hashB);
        self::assertSame(64, strlen($hashA));
        self::assertSame(64, strlen($hashB));
    }
}
