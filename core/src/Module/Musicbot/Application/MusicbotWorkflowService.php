<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflow;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflowAction;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflowCondition;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflowExecution;
use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowActionType;
use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowConditionType;
use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowTriggerType;
use App\Repository\MusicbotWorkflowExecutionRepository;
use App\Repository\MusicbotWorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotWorkflowService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotWorkflowRepository $workflowRepository,
        private readonly MusicbotWorkflowExecutionRepository $executionRepository,
        private readonly MusicbotWorkflowExecutor $executor,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly AuditLogger $auditLogger,
        private readonly MusicbotQuotaService $quotaService,
    ) {
    }

    /**
     * @param array<array{type: string, value?: string, sort_order?: int}> $conditions
     * @param array<array{type: string, config?: array<string, mixed>, sort_order?: int}> $actions
     * @throws \InvalidArgumentException
     */
    public function create(
        User $customer,
        MusicbotInstance $instance,
        string $name,
        MusicbotWorkflowTriggerType $triggerType,
        array $triggerConfig,
        ?string $description,
        bool $enabled,
        array $conditions,
        array $actions,
    ): MusicbotWorkflow {
        $this->assertCustomerOwnsInstance($customer, $instance);
        $this->quotaService->assertWorkflowsAllowed($customer);
        $this->validateName($name);
        $this->validateActions($actions);

        $workflow = new MusicbotWorkflow($customer, $instance, $name, $triggerType, $triggerConfig, $description, $enabled);
        $this->attachConditions($workflow, $conditions);
        $this->attachActions($workflow, $actions);

        $this->entityManager->persist($workflow);
        $this->entityManager->flush();

        $this->runtimeEventService->record($instance, 'workflow.created', 'info', sprintf('Workflow "%s" created.', $name), ['trigger' => $triggerType->value]);
        $this->auditLogger->log($customer, 'musicbot.workflow_created', ['instance_id' => $instance->getId(), 'workflow_id' => $workflow->getId(), 'trigger' => $triggerType->value]);

        return $workflow;
    }

    /**
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException
     */
    public function update(User $customer, MusicbotWorkflow $workflow, array $data): MusicbotWorkflow
    {
        $this->assertOwnership($customer, $workflow);

        $name = trim((string) ($data['name'] ?? $workflow->getName()));
        $description = array_key_exists('description', $data) ? ((string) $data['description'] ?: null) : $workflow->getDescription();
        $triggerType = isset($data['trigger_type'])
            ? MusicbotWorkflowTriggerType::from((string) $data['trigger_type'])
            : $workflow->getTriggerType();
        $triggerConfig = array_key_exists('trigger_config', $data) ? (array) $data['trigger_config'] : $workflow->getTriggerConfig();
        $enabled = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : $workflow->isEnabled();

        $this->validateName($name);

        $workflow->update($name, $description, $triggerType, $triggerConfig, $enabled);

        if (array_key_exists('conditions', $data)) {
            $workflow->clearConditions();
            $this->attachConditions($workflow, (array) $data['conditions']);
        }

        if (array_key_exists('actions', $data)) {
            $this->validateActions((array) $data['actions']);
            $workflow->clearActions();
            $this->attachActions($workflow, (array) $data['actions']);
        }

        $this->entityManager->flush();

        $this->runtimeEventService->record($workflow->getInstance(), 'workflow.updated', 'info', sprintf('Workflow "%s" updated.', $name), ['trigger' => $triggerType->value]);
        $this->auditLogger->log($customer, 'musicbot.workflow_updated', ['workflow_id' => $workflow->getId()]);

        return $workflow;
    }

    public function delete(User $customer, MusicbotWorkflow $workflow): void
    {
        $this->assertOwnership($customer, $workflow);

        $instance = $workflow->getInstance();
        $workflowId = $workflow->getId();
        $workflowName = $workflow->getName();

        $this->entityManager->remove($workflow);
        $this->entityManager->flush();

        $this->runtimeEventService->record($instance, 'workflow.deleted', 'info', sprintf('Workflow "%s" deleted.', $workflowName));
        $this->auditLogger->log($customer, 'musicbot.workflow_deleted', ['instance_id' => $instance->getId(), 'workflow_id' => $workflowId]);
    }

    public function toggle(User $customer, MusicbotWorkflow $workflow, bool $enabled): void
    {
        $this->assertOwnership($customer, $workflow);
        $workflow->setEnabled($enabled);
        $this->entityManager->flush();

        $this->runtimeEventService->record(
            $workflow->getInstance(),
            'workflow.updated',
            'info',
            sprintf('Workflow "%s" %s.', $workflow->getName(), $enabled ? 'enabled' : 'disabled'),
        );
    }

    /**
     * Manual test run — bypasses the enabled flag but enforces ownership.
     *
     * @param array<string, mixed> $context
     */
    public function testRun(User $customer, MusicbotWorkflow $workflow, array $context = []): MusicbotWorkflowExecution
    {
        $this->assertOwnership($customer, $workflow);

        $execution = $this->executor->execute($workflow, array_merge($context, ['_test' => true]));

        $this->auditLogger->log($customer, 'musicbot.workflow_test_run', ['workflow_id' => $workflow->getId(), 'status' => $execution->getStatus()->value]);

        return $execution;
    }

    /**
     * Dispatch workflows for a given trigger on a specific instance.
     * Used internally when an event fires.
     *
     * @param array<string, mixed> $context
     * @return MusicbotWorkflowExecution[]
     */
    public function dispatchForTrigger(MusicbotInstance $instance, MusicbotWorkflowTriggerType $triggerType, array $context = []): array
    {
        $workflows = $this->workflowRepository->findEnabledByTriggerTypeAndInstance($triggerType, $instance);
        $executions = [];

        foreach ($workflows as $workflow) {
            $executions[] = $this->executor->execute($workflow, $context);
        }

        return $executions;
    }

    /** @return array<string, mixed> */
    public function normalize(MusicbotWorkflow $workflow): array
    {
        return [
            'id' => $workflow->getId(),
            'name' => $workflow->getName(),
            'description' => $workflow->getDescription(),
            'trigger_type' => $workflow->getTriggerType()->value,
            'trigger_config' => $workflow->getTriggerConfig(),
            'enabled' => $workflow->isEnabled(),
            'execution_count' => $workflow->getExecutionCount(),
            'last_triggered_at' => $workflow->getLastTriggeredAt()?->format(\DateTimeInterface::ATOM),
            'conditions' => array_values(array_map(
                fn (MusicbotWorkflowCondition $c): array => [
                    'id' => $c->getId(),
                    'type' => $c->getType()->value,
                    'value' => $c->getValue(),
                    'sort_order' => $c->getSortOrder(),
                ],
                $workflow->getConditions()->toArray(),
            )),
            'actions' => array_values(array_map(
                fn (MusicbotWorkflowAction $a): array => [
                    'id' => $a->getId(),
                    'type' => $a->getType()->value,
                    'config' => $a->getConfig(),
                    'sort_order' => $a->getSortOrder(),
                ],
                $workflow->getActions()->toArray(),
            )),
            'instance_id' => $workflow->getInstance()->getId(),
            'created_at' => $workflow->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $workflow->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function normalizeExecution(MusicbotWorkflowExecution $execution): array
    {
        return [
            'id' => $execution->getId(),
            'workflow_id' => $execution->getWorkflow()->getId(),
            'status' => $execution->getStatus()->value,
            'triggered_at' => $execution->getTriggeredAt()->format(\DateTimeInterface::ATOM),
            'completed_at' => $execution->getCompletedAt()?->format(\DateTimeInterface::ATOM),
            'duration_ms' => $execution->getDurationMs(),
            'log' => $execution->getLog(),
            'error' => $execution->getError(),
            'trigger_context' => $execution->getTriggerContext(),
        ];
    }

    private function validateName(string $name): void
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Workflow name must not be empty.');
        }
    }

    /**
     * @param array<mixed> $actions
     */
    private function validateActions(array $actions): void
    {
        foreach ($actions as $i => $action) {
            $type = (string) ($action['type'] ?? '');
            if (MusicbotWorkflowActionType::tryFrom($type) === null) {
                throw new \InvalidArgumentException(sprintf('Unknown action type "%s" at index %d.', $type, $i));
            }

            if (MusicbotWorkflowActionType::from($type) === MusicbotWorkflowActionType::SendWebhook) {
                $url = (string) ($action['config']['url'] ?? '');
                if ($url === '') {
                    throw new \InvalidArgumentException(sprintf('Action at index %d (send_webhook) requires a "url" in config.', $i));
                }
            }
        }
    }

    /**
     * @param array<mixed> $conditionsData
     */
    private function attachConditions(MusicbotWorkflow $workflow, array $conditionsData): void
    {
        foreach ($conditionsData as $i => $data) {
            $type = MusicbotWorkflowConditionType::tryFrom((string) ($data['type'] ?? ''));
            if ($type === null) {
                throw new \InvalidArgumentException(sprintf('Unknown condition type "%s" at index %d.', $data['type'] ?? '', $i));
            }
            $condition = new MusicbotWorkflowCondition(
                $workflow,
                $type,
                isset($data['value']) ? (string) $data['value'] : null,
                (int) ($data['sort_order'] ?? $i),
            );
            $workflow->addCondition($condition);
        }
    }

    /**
     * @param array<mixed> $actionsData
     */
    private function attachActions(MusicbotWorkflow $workflow, array $actionsData): void
    {
        foreach ($actionsData as $i => $data) {
            $type = MusicbotWorkflowActionType::from((string) ($data['type'] ?? ''));
            $action = new MusicbotWorkflowAction(
                $workflow,
                $type,
                is_array($data['config'] ?? null) ? $data['config'] : [],
                (int) ($data['sort_order'] ?? $i),
            );
            $workflow->addAction($action);
        }
    }

    private function assertCustomerOwnsInstance(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Musicbot instance does not belong to the current customer.');
        }
    }

    private function assertOwnership(User $customer, MusicbotWorkflow $workflow): void
    {
        if ($workflow->getCustomer()->getId() !== $customer->getId()) {
            throw new \RuntimeException('Workflow does not belong to the current customer.');
        }
    }
}
