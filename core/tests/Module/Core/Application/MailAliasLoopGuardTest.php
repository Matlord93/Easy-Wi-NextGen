<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\MailAliasLoopGuard;
use App\Module\Core\Domain\Entity\MailAlias;
use PHPUnit\Framework\TestCase;

final class MailAliasLoopGuardTest extends TestCase
{
    public function testDetectsSimpleLoop(): void
    {
        $guard = new MailAliasLoopGuard();

        $existing = [$this->mockAlias('help@example.test', ['sales@example.test'])];
        self::assertTrue($guard->wouldCreateLoop('sales@example.test', ['help@example.test'], $existing));
    }


    public function testDetectsTransitiveLoop(): void
    {
        $guard = new MailAliasLoopGuard();

        $existing = [
            $this->mockAlias('a@example.test', ['b@example.test']),
            $this->mockAlias('b@example.test', ['c@example.test']),
        ];

        self::assertTrue($guard->wouldCreateLoop('c@example.test', ['a@example.test'], $existing));
    }

    public function testAllowsExternalDestinations(): void
    {
        $guard = new MailAliasLoopGuard();

        $existing = [$this->mockAlias('help@example.test', ['support@external.test'])];
        self::assertFalse($guard->wouldCreateLoop('sales@example.test', ['help@example.test'], $existing));
    }

    private function mockAlias(string $address, array $destinations): MailAlias
    {
        $alias = $this->createMock(MailAlias::class);
        $alias->method('getAddress')->willReturn($address);
        $alias->method('getDestinations')->willReturn($destinations);

        return $alias;
    }
}
