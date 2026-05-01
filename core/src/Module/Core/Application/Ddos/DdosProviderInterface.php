<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Ddos;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface DdosProviderInterface
{
    public function getName(): string;
}
