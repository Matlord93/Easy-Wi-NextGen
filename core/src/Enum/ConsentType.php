<?php

declare(strict_types=1);

namespace App\Enum;

enum ConsentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case Marketing = 'marketing';
}
