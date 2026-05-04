<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Entity\Concerns;

/**
 * Provides a setId() helper for use in unit tests where Doctrine is not
 * managing the entity lifecycle. Must never be called in production code.
 *
 * @internal
 */
trait TestSettableIdTrait
{
    public function setId(int $id): void
    {
        $this->id = $id;
    }
}
