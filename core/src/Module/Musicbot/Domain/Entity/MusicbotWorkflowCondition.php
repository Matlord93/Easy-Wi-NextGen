<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowConditionType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'musicbot_workflow_conditions')]
#[ORM\Index(name: 'idx_musicbot_wf_cond_workflow', columns: ['workflow_id'])]
class MusicbotWorkflowCondition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotWorkflow::class, inversedBy: 'conditions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotWorkflow $workflow;

    #[ORM\Column(enumType: MusicbotWorkflowConditionType::class, length: 60)]
    private MusicbotWorkflowConditionType $type;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $value = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function __construct(
        MusicbotWorkflow $workflow,
        MusicbotWorkflowConditionType $type,
        ?string $value = null,
        int $sortOrder = 0,
    ) {
        $this->workflow = $workflow;
        $this->type = $type;
        $this->value = $value;
        $this->sortOrder = $sortOrder;
    }

    public function getId(): ?int { return $this->id; }
    public function getWorkflow(): MusicbotWorkflow { return $this->workflow; }
    public function getType(): MusicbotWorkflowConditionType { return $this->type; }
    public function getValue(): ?string { return $this->value; }
    public function getSortOrder(): int { return $this->sortOrder; }
}
