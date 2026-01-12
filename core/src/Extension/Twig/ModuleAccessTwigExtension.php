<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Service\ModuleRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ModuleAccessTwigExtension extends AbstractExtension
{
    public function __construct(private readonly ModuleRegistry $moduleRegistry)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('module_enabled', [$this, 'isEnabled']),
        ];
    }

    public function isEnabled(string $moduleKey): bool
    {
        return $this->moduleRegistry->isEnabled($moduleKey);
    }
}
