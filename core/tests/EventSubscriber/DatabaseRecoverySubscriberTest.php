<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Module\Core\Application\MigrationStatusProviderInterface;
use App\Module\Core\EventSubscriber\DatabaseRecoverySubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DatabaseRecoverySubscriberTest extends TestCase
{
    public function testSchemaMismatchRedirectsToRecoveryWhenMigrationsPending(): void
    {
        $subscriber = $this->createSubscriber(2, '/system/recovery/database');
        $event = $this->createExceptionEvent('/admin/users', new \RuntimeException('SQLSTATE[42S22]: Column not found'));

        $subscriber->onKernelException($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(302, $event->getResponse()->getStatusCode());
        self::assertSame('/system/recovery/database', $event->getResponse()->headers->get('Location'));
    }

    public function testSchemaMismatchDoesNotRedirectWhenDatabaseIsUpToDate(): void
    {
        $subscriber = $this->createSubscriber(0, '/system/recovery/database');
        $event = $this->createExceptionEvent('/admin/users', new \RuntimeException('SQLSTATE[42S22]: Column not found'));

        $subscriber->onKernelException($event);

        self::assertFalse($event->hasResponse());
    }

    public function testExcludedRecoveryPathIsIgnored(): void
    {
        $subscriber = $this->createSubscriber(3, '/system/recovery/database');
        $event = $this->createExceptionEvent('/system/recovery/database', new \RuntimeException('SQLSTATE[42S22]: Column not found'));

        $subscriber->onKernelException($event);

        self::assertFalse($event->hasResponse());
    }

    public function testSchemaMismatchRedirectsWhenMigrationStatusCannotBeDetermined(): void
    {
        $subscriber = $this->createSubscriber(null, '/system/recovery/database', 'migration_status_failed');
        $event = $this->createExceptionEvent('/admin/users', new \RuntimeException('SQLSTATE[42S22]: Column not found'));

        $subscriber->onKernelException($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(302, $event->getResponse()->getStatusCode());
        self::assertSame('/system/recovery/database', $event->getResponse()->headers->get('Location'));
    }

    private function createSubscriber(?int $pendingMigrations, string $recoveryPath, ?string $error = null): DatabaseRecoverySubscriber
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator
            ->method('generate')
            ->with('system_recovery_database')
            ->willReturn($recoveryPath);

        $migrationStatusProvider = $this->createMock(MigrationStatusProviderInterface::class);
        $migrationStatusProvider
            ->method('getMigrationStatus')
            ->willReturn([
                'pending' => $pendingMigrations,
                'executedUnavailable' => 0,
                'error' => $error,
            ]);

        return new DatabaseRecoverySubscriber($urlGenerator, $migrationStatusProvider);
    }

    private function createExceptionEvent(string $path, \Throwable $throwable): ExceptionEvent
    {
        $kernel = new class () implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
            {
                throw new \LogicException('Not used in this test.');
            }
        };

        return new ExceptionEvent(
            $kernel,
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
