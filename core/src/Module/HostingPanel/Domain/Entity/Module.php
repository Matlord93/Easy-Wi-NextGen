<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hp_module')]
class Module
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $type;

    #[ORM\Column(length: 60)]
    private string $name;

    #[ORM\Column(type: 'json')]
    private array $desiredState = [];

    #[ORM\Column(type: 'json')]
    private array $actualState = [];

    #[ORM\ManyToOne(targetEntity: Node::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Node $node;

    public function __construct(Node $node, string $type, string $name, array $desiredState = [])
    {
        $this->node = $node;
        $this->type = $type;
        $this->name = $name;
        $this->desiredState = $desiredState;
    }
}
