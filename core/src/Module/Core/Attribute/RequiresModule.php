<?php

declare(strict_types=1);

namespace App\Module\Core\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class RequiresModule
{
    public function __construct(public readonly string $moduleKey) {}
}
