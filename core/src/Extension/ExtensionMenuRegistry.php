<?php

declare(strict_types=1);

namespace App\Extension;

final class ExtensionMenuRegistry
{
    /**
     * @param iterable<ExtensionMenuProviderInterface> $menuProviders
     */
    public function __construct(private readonly iterable $menuProviders)
    {
    }

    /**
     * @return array<int, array{label: string, href: string, iconSvg: ?string}>
     */
    public function adminItems(): array
    {
        return $this->collect('admin');
    }

    /**
     * @return array<int, array{label: string, href: string, iconSvg: ?string}>
     */
    public function customerItems(): array
    {
        return $this->collect('customer');
    }

    /**
     * @return array<int, array{label: string, href: string, iconSvg: ?string}>
     */
    private function collect(string $scope): array
    {
        $items = [];

        foreach ($this->menuProviders as $provider) {
            $menuItems = $scope === 'admin'
                ? $provider->adminMenuItems()
                : $provider->customerMenuItems();
            foreach ($menuItems as $item) {
                $items[] = [
                    'label' => $item->label,
                    'href' => $item->href,
                    'iconSvg' => $item->iconSvg,
                ];
            }
        }

        return $items;
    }
}
