<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Contracts;

use App\Module\Core\Domain\Entity\ShopContract;

interface ContractResourceReservation
{
    public function reserveResource(ShopContract $contract): void;
}
