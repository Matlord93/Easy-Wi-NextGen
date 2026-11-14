<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Module\Nodes\Application\InfrastructureUsage;
use App\Module\Core\Domain\Entity\Agent;
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
            new TwigFunction('infra_node_usage', [$this, 'node']),
            new TwigFunction('infra_kvm_usage', [$this, 'kvm']),
            new TwigFunction('infra_pve_usage', [$this, 'proxmox']),
        ];
    }

    public function node(Agent $node): array
    {
        return $this->usage->node($node);
    }

    public function kvm(Agent $node, string $uuid): array
    {
        return $this->usage->kvm($node, $uuid);
    }

    public function proxmox(array $metrics): array
    {
        return $this->usage->proxmox($metrics);
    }
}
