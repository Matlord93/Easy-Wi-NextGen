<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\SecurityEvent;
use PHPUnit\Framework\TestCase;

final class SecurityEventTest extends TestCase
{
    public function testDedupAndExpiryDefaultsAreGenerated(): void
    {
        $agent = new Agent('node-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $occurredAt = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $event = new SecurityEvent($agent, 'blocked', 'fail2ban', 'jail:sshd', '10.0.0.1', 'sshd', 1, $occurredAt);

        self::assertSame(64, strlen($event->getDedupKey()));
        self::assertGreaterThan($event->getCreatedAt(), $event->getExpiresAt());
    }
}
