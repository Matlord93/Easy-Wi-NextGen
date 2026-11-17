<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Resource;

use App\Module\Core\Domain\Entity\Agent;

final readonly class ManagedBackupResource
{
    /** @param array<string, scalar|array|null> $descriptor */
    public function __construct(public Agent $agent, public string $customerId, public array $descriptor, public ?int $mailDomainId = null, public ?int $domainId = null)
    {
    }

    public function identity(): string
    {
        $identity = $this->descriptor;
        if (isset($identity['database']) && is_array($identity['database'])) {
            unset($identity['database']['password']);
        }
        return hash('sha256', json_encode([$this->agent->getId(), $this->customerId, $identity], JSON_THROW_ON_ERROR));
    }
}
