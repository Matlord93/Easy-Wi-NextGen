<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application\Billing;

use App\Module\Core\Application\Billing\PaymentProviderInterface;
use App\Module\Core\Application\Billing\PaymentProviderRegistry;
use PHPUnit\Framework\TestCase;

final class PaymentProviderSelectionTest extends TestCase
{
    public function testConfiguredPaymentMethodSelectsTheProviderThatSupportsIt(): void
    {
        $manual = $this->createStub(PaymentProviderInterface::class);
        $manual->method('getName')->willReturn('manual');
        $manual->method('supportedMethods')->willReturn(['bank_transfer']);
        $stripe = $this->createStub(PaymentProviderInterface::class);
        $stripe->method('getName')->willReturn('stripe');
        $stripe->method('supportedMethods')->willReturn(['card']);
        $registry = new PaymentProviderRegistry([$manual, $stripe]);
        self::assertSame($stripe, $registry->get('card'));
        self::assertSame($stripe, $registry->get('stripe'));
        self::assertSame($manual, $registry->get('bank_transfer'));
        self::assertNull($registry->get('unknown-method'));
    }
}
