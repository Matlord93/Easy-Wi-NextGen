<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Domain\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hp_node')]
class Node
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $fqdn;

    #[ORM\Column(length: 45)]
    private string $ipAddress;

    #[ORM\Column]
    private bool $online = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    /** @var Collection<int, Agent> */
    #[ORM\OneToMany(mappedBy: 'node', targetEntity: Agent::class)]
    private Collection $agents;

    public function __construct(string $name, string $fqdn, string $ipAddress)
    {
        $this->name = $name;
        $this->fqdn = $fqdn;
        $this->ipAddress = $ipAddress;
        $this->agents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setOnline(bool $online): void
    {
        $this->online = $online;
        $this->lastSeenAt = new \DateTimeImmutable();
    }
}
