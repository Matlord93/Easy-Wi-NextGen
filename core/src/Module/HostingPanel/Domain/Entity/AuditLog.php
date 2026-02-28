<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hp_audit_log')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $actor;

    #[ORM\Column(length: 120)]
    private string $action;

    #[ORM\Column(length: 120)]
    private string $targetType;

    #[ORM\Column(length: 120)]
    private string $targetId;

    #[ORM\Column(type: 'json')]
    private array $beforeState = [];

    #[ORM\Column(type: 'json')]
    private array $afterState = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $actor, string $action, string $targetType, string $targetId, array $beforeState, array $afterState)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->beforeState = $beforeState;
        $this->afterState = $afterState;
        $this->createdAt = new \DateTimeImmutable();
    }
}
