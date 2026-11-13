<?php

declare(strict_types=1);

namespace App\Module\Nodes\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity, ORM\Table(name: 'infrastructure_monitor_policies')]
class InfrastructureMonitorPolicy
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 64)] private string $resourceKey,
        #[ORM\Column(type: 'json')] private array $thresholds,
        #[ORM\Column(type: 'json')] private array $state,
        #[ORM\Column] private int $revision = 1,
    ) {
    }
}
