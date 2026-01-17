<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Domain\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Module\Core\Application\AuditLogHasher;
use App\Module\Core\Application\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    public function testLogChainsHashValues(): void
    {
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->expects(self::once())
            ->method('findLatestHash')
            ->willReturn('prev-hash');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(AuditLog::class));

        $hasher = new AuditLogHasher();
        $logger = new AuditLogger($repository, $hasher, $entityManager);

        $auditLog = $logger->log(null, 'user.created', ['email' => 'admin@example.test']);

        self::assertSame('prev-hash', $auditLog->getHashPrev());
        self::assertSame($hasher->compute('prev-hash', $auditLog), $auditLog->getHashCurrent());
    }
}
