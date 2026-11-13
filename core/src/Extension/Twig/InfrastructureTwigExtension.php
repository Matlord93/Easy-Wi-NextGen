<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Module\Nodes\Application\InfrastructureUsage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class InfrastructureTwigExtension extends AbstractExtension
{
    public function __construct(private readonly InfrastructureUsage $usage)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('infra_node_usage', $this->usage->node(...)),
            new TwigFunction('infra_kvm_usage', $this->usage->kvm(...)),
            new TwigFunction('infra_pve_usage', $this->usage->proxmox(...)),
        ];
    }
}
