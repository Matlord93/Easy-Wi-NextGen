<?php

declare(strict_types=1);

namespace App\Service\Ddos;

interface DdosProviderInterface
{
    public function getName(): string;
}
