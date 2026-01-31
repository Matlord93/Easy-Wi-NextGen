<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Sinusbot;

final class SinusbotQuotaValidator
{
    public function __construct(
        private readonly int $minQuota,
        private readonly int $maxQuota,
    ) {
    }

    public function validate(int $quota): void
    {
        if ($quota < $this->minQuota || $quota > $this->maxQuota) {
            throw new \InvalidArgumentException(sprintf(
                'Quota must be between %d and %d.',
                $this->minQuota,
                $this->maxQuota,
            ));
        }
    }

    public function getMinQuota(): int
    {
        return $this->minQuota;
    }

    public function getMaxQuota(): int
    {
        return $this->maxQuota;
    }
}
