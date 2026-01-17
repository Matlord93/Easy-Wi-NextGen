<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ddos;

interface DdosProviderInterface
{
    public function getName(): string;
}
