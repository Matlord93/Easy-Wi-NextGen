<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application;

use App\Module\Core\Domain\Entity\Instance;

interface TemplateInstallResolverInterface
{
    public function resolveInstallCommand(Instance $instance): string;
    public function resolveUpdateCommand(Instance $instance): string;
    public function resolveJavaBin(Instance $instance): ?string;
}
