<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Adapter;

interface PanelAdapterInterface
{
    /**
     * @return list<string>
     */
    public function discoverCapabilities(PanelAdapterContext $context): array;

    public function executeAction(string $action, array $payload, PanelAdapterContext $context): PanelActionResult;
}
