<?php

declare(strict_types=1);

namespace App\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.extension.menu_provider')]
interface ExtensionMenuProviderInterface
{
    /**
     * @return MenuItem[]
     */
    public function adminMenuItems(): array;

    /**
     * @return MenuItem[]
     */
    public function customerMenuItems(): array;
}
