<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflow;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflowCondition;
use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowConditionType;
use App\Repository\MusicbotPluginRepository;
use App\Repository\MusicbotQueueItemRepository;

final class MusicbotWorkflowEvaluator
{
    public function __construct(
        private readonly MusicbotQueueItemRepository $queueItemRepository,
        private readonly MusicbotPluginRepository $pluginRepository,
    ) {
    }

    /**
     * Returns true when all conditions pass (AND logic).
     *
     * @param array<string, mixed> $context
     */
    public function evaluate(MusicbotWorkflow $workflow, array $context): bool
    {
        foreach ($workflow->getConditions() as $condition) {
            if (!$this->evaluateOne($condition, $workflow->getInstance(), $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function evaluateOne(MusicbotWorkflowCondition $condition, MusicbotInstance $instance, array $context): bool
    {
        $value = $condition->getValue() ?? '';

        return match ($condition->getType()) {
            MusicbotWorkflowConditionType::PlatformIs =>
                ($context['platform'] ?? '') === $value,

            MusicbotWorkflowConditionType::TeamspeakProfileIs =>
                ($context['teamspeak_profile'] ?? '') === $value,

            MusicbotWorkflowConditionType::QueueLengthEquals =>
                count($this->queueItemRepository->findQueueForInstanceOrdered($instance)) === (int) $value,

            MusicbotWorkflowConditionType::QueueLengthGreater =>
                count($this->queueItemRepository->findQueueForInstanceOrdered($instance)) > (int) $value,

            MusicbotWorkflowConditionType::QueueLengthLess =>
                count($this->queueItemRepository->findQueueForInstanceOrdered($instance)) < (int) $value,

            MusicbotWorkflowConditionType::TimeInRange =>
                $this->evaluateTimeInRange($value),

            MusicbotWorkflowConditionType::UserCountGreater =>
                ((int) ($context['user_count'] ?? 0)) > (int) $value,

            MusicbotWorkflowConditionType::UserCountLess =>
                ((int) ($context['user_count'] ?? 0)) < (int) $value,

            MusicbotWorkflowConditionType::PluginEnabled =>
                $this->isPluginEnabled($instance, $value),

            MusicbotWorkflowConditionType::InstanceStatusIs =>
                $instance->getStatus()->value === $value,
        };
    }

    /**
     * Expects value in "HH:MM-HH:MM" format (24h, UTC).
     */
    private function evaluateTimeInRange(string $value): bool
    {
        if (!preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $value, $m)) {
            return false;
        }

        $now = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Hi');
        $from = (int) str_replace(':', '', $m[1]);
        $to = (int) str_replace(':', '', $m[2]);

        if ($from <= $to) {
            return $now >= $from && $now <= $to;
        }

        // Wraps midnight
        return $now >= $from || $now <= $to;
    }

    private function isPluginEnabled(MusicbotInstance $instance, string $identifier): bool
    {
        $plugin = $this->pluginRepository->findOneByIdentifierForInstance($identifier, $instance, $instance->getCustomer());

        return $plugin !== null && $plugin->isEnabled();
    }
}
