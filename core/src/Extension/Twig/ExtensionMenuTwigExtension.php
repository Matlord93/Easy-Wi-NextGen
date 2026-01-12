<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Extension\ExtensionMenuRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ExtensionMenuTwigExtension extends AbstractExtension
{
    public function __construct(private readonly ExtensionMenuRegistry $menuRegistry)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('extension_menu_items', [$this, 'menuItems']),
        ];
    }

    /**
     * @return array<int, array{label: string, href: string, iconSvg: ?string}>
     */
    public function menuItems(string $scope): array
    {
        return $scope === 'customer'
            ? $this->menuRegistry->customerItems()
            : $this->menuRegistry->adminItems();
    }
}
