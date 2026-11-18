<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Monitoring;

use App\Module\Core\Domain\Entity\User;

final readonly class InfrastructureTarget
{
    public string $key;

    public function __construct(public string $provider, string $connection, public string $kind, string $reference, string $epoch, public string $label, public string $adminUrl, public ?User $customer = null, public ?string $customerUrl = null, public ?int $visibleSince = null)
    {
        $this->key = hash('sha256', json_encode([$provider, $connection, $kind, $reference, $epoch], JSON_THROW_ON_ERROR));
    }
}
