<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Billing;

final class PaymentInstruction
{
    public function __construct(
        public readonly string $provider,
        public readonly string $reference,
        public readonly array $metadata = [],
        public readonly ?string $redirectUrl = null,
    ) {
    }
}
