<?php

declare(strict_types=1);

namespace App\Module\Core\Domain\Enum;

enum ConsentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Marketing = 'marketing';
}
