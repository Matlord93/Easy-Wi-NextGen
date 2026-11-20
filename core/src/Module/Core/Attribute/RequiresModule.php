<?php

declare(strict_types=1);

namespace App\Module\Core\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class RequiresModule
{
    public function __construct(public readonly string $moduleKey)
    {
    }
}
