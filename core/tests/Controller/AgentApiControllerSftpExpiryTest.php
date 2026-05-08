<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\PanelCustomer\UI\Controller\Api\AgentApiController;
use PHPUnit\Framework\TestCase;

final class AgentApiControllerSftpExpiryTest extends TestCase
{
    public function testRevealExpiryIsExtendedFromJobCompletionWhenQueuedExpiryIsAlreadyPast(): void
    {
        $controller = (new \ReflectionClass(AgentApiController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentApiController::class, 'resolveSftpPasswordRevealExpiresAt');
        $method->setAccessible(true);

        $completedAt = new \DateTimeImmutable('2026-05-08 10:30:00 UTC');
        $expiresAt = $method->invoke($controller, [
            'expires_at' => '2026-05-08T10:00:00+00:00',
        ], $completedAt);

        self::assertInstanceOf(\DateTimeImmutable::class, $expiresAt);
        self::assertSame('2026-05-08T10:45:00+00:00', $expiresAt->format(DATE_RFC3339));
    }

    public function testRevealExpiryKeepsFutureQueuedExpiry(): void
    {
        $controller = (new \ReflectionClass(AgentApiController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AgentApiController::class, 'resolveSftpPasswordRevealExpiresAt');
        $method->setAccessible(true);

        $completedAt = new \DateTimeImmutable('2026-05-08 10:30:00 UTC');
        $expiresAt = $method->invoke($controller, [
            'expires_at' => '2026-05-08T11:00:00+00:00',
        ], $completedAt);

        self::assertInstanceOf(\DateTimeImmutable::class, $expiresAt);
        self::assertSame('2026-05-08T11:00:00+00:00', $expiresAt->format(DATE_RFC3339));
    }
}
