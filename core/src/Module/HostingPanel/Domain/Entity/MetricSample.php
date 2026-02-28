<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hp_metric_sample')]
class MetricSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Node::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Node $node;

    #[ORM\Column(length: 40)]
    private string $metric;

    #[ORM\Column(type: 'float')]
    private float $value;

    #[ORM\Column]
    private \DateTimeImmutable $sampledAt;

    public function __construct(Node $node, string $metric, float $value)
    {
        $this->node = $node;
        $this->metric = $metric;
        $this->value = $value;
        $this->sampledAt = new \DateTimeImmutable();
    }
}
