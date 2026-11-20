<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\ShopContract;
use App\Module\Core\Domain\Enum\ShopProductKind;

interface ContractResourceProvider
{
    /** @return list<ShopProductKind> */
    public function kinds(): array;

    public function provision(ShopContract $contract): void;

    public function readyAt(ShopContract $contract): ?\DateTimeImmutable;

    public function stop(ShopContract $contract): bool;
}
