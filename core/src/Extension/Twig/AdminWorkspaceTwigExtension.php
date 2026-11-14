<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Module\PanelAdmin\Application\AdminWorkspaceGuide;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminWorkspaceTwigExtension extends AbstractExtension
{
    public function __construct(private readonly AdminWorkspaceGuide $guide)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('admin_workspace_context', [$this, 'context'])];
    }

    public function context(string $activeNav): array
    {
        return $this->guide->context($activeNav);
    }
}
