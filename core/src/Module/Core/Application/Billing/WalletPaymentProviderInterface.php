<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

use App\Module\Core\Domain\Entity\WalletTopup;

interface WalletPaymentProviderInterface
{
    public function createWalletPaymentIntent(WalletTopup $topup, ?string $method = null): PaymentInstruction;
}
