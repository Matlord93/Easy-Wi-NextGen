<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\InstallerService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class InstallerDatabaseReadinessTest extends TestCase
{
    public function testReadinessChecksTheSuppliedConfiguredConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $service = (new \ReflectionClass(InstallerService::class))->newInstanceWithoutConstructor();

        (new \ReflectionMethod(InstallerService::class, 'waitForDatabaseReady'))->invoke($service, $connection);

        self::assertTrue($connection->isConnected());
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
        $connection->close();
    }

    public function testFailedConnectionsAreClosedBeforeRetrying(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->expects(self::once())->method('free');
        $attempt = 0;
        $connection->expects(self::exactly(2))->method('executeQuery')->with('SELECT 1')
            ->willReturnCallback(static function () use (&$attempt, $result): Result {
                if (++$attempt === 1) {
                    throw new \RuntimeException('Synthetic unavailable connection.');
                }
                return $result;
            });
        $connection->expects(self::once())->method('close');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::once())->method('info');
        $service = (new \ReflectionClass(InstallerService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(InstallerService::class, 'logger'))->setValue($service, $logger);

        (new \ReflectionMethod(InstallerService::class, 'waitForDatabaseReady'))->invoke($service, $connection);
    }
}
