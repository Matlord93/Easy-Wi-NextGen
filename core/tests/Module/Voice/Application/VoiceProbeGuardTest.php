<?php

declare(strict_types=1);

namespace App\Tests\Module\Voice\Application;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\VoiceInstance;
use App\Module\Core\Domain\Entity\VoiceNode;
use App\Module\Core\Domain\Entity\VoiceRateLimitState;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Voice\Application\VoiceProbeGuard;
use App\Module\Voice\Application\VoiceRateLimitStateStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class VoiceProbeGuardTest extends TestCase
{
    public function testCircuitOpensAfterConsecutiveFailures(): void
    {
        $node = new VoiceNode('n1', 'ts3', '127.0.0.1', 10011);
        $customer = new User('customer@example.test', UserType::Customer);
        $instance = new VoiceInstance($customer, $node, '1', 'TS3 One');
        $state = new VoiceRateLimitState($node, 'ts3');

        $store = $this->createMock(VoiceRateLimitStateStoreInterface::class);
        $store->method('findOneByNodeAndProvider')->willReturn($state);

        $em = $this->createMock(EntityManagerInterface::class);
        $guard = new VoiceProbeGuard($store, $em);

        $guard->registerFailure($instance);
        $guard->registerFailure($instance);
        $guard->registerFailure($instance);

        self::assertGreaterThanOrEqual(3, $state->getConsecutiveFailures());
        self::assertNotNull($state->getCircuitOpenUntil());
    }

    public function testAllowReturnsRetryAfterWhenLocked(): void
    {
        $node = new VoiceNode('n1', 'ts3', '127.0.0.1', 10011);
        $customer = new User('customer@example.test', UserType::Customer);
        $instance = new VoiceInstance($customer, $node, '1', 'TS3 One');
        $state = new VoiceRateLimitState($node, 'ts3');
        $state->setTokens(0.4);
        $state->setLockedUntil(new \DateTimeImmutable('+5 seconds'));

        $store = $this->createMock(VoiceRateLimitStateStoreInterface::class);
        $store->method('findOneByNodeAndProvider')->willReturn($state);

        $em = $this->createMock(EntityManagerInterface::class);
        $guard = new VoiceProbeGuard($store, $em);

        $result = $guard->allow($instance);

        self::assertFalse($result['allowed']);
        self::assertSame('voice_rate_limited', $result['error_code']);
        self::assertGreaterThanOrEqual(1, $result['retry_after']);
    }
}
