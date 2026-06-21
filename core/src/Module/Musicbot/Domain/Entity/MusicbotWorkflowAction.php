<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Domain\Entity;

use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowActionType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'musicbot_workflow_actions')]
class MusicbotWorkflowAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MusicbotWorkflow::class, inversedBy: 'actions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MusicbotWorkflow $workflow;

    #[ORM\Column(enumType: MusicbotWorkflowActionType::class, length: 60)]
    private MusicbotWorkflowActionType $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\Column]
    private int $sortOrder = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        MusicbotWorkflow $workflow,
        MusicbotWorkflowActionType $type,
        array $config = [],
        int $sortOrder = 0,
    ) {
        $this->workflow = $workflow;
        $this->type = $type;
        $this->config = $config;
        $this->sortOrder = $sortOrder;
    }

    public function getId(): ?int { return $this->id; }
    public function getWorkflow(): MusicbotWorkflow { return $this->workflow; }
    public function getType(): MusicbotWorkflowActionType { return $this->type; }
    /** @return array<string, mixed> */ public function getConfig(): array { return $this->config; }
    public function getSortOrder(): int { return $this->sortOrder; }
}
