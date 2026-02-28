<?php

declare(strict_types=1);

namespace App\Tests\HostingPanel;

use App\Module\HostingPanel\Application\Audit\AuditLogWriter;
use App\Module\HostingPanel\Domain\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class AuditLogWriterTest extends TestCase
{
    public function testWritePersistsAuditEntry(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(AuditLog::class));

        $writer = new AuditLogWriter($em);
        $writer->write('user:1', 'update', 'Node', '1', ['online' => false], ['online' => true]);
    }
}
