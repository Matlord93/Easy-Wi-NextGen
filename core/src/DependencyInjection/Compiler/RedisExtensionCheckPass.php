<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RedisExtensionCheckPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (extension_loaded('redis')) {
            return;
        }

        if ($container->hasDefinition('Redis')) {
            $container->removeDefinition('Redis');
        }
    }
}
