<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Module\Musicbot\Application\MusicbotAutoDjService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflow;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflowAction;
use App\Module\Musicbot\Domain\Entity\MusicbotWorkflowExecution;
use App\Module\Musicbot\Domain\Enum\MusicbotWorkflowActionType;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotWorkflowExecutor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly MusicbotWorkflowEvaluator $evaluator,
        private readonly AgentJobDispatcher $jobDispatcher,
        private readonly MusicbotAutoDjService $autoDjService,
    ) {
    }

    /**
     * Evaluates conditions and executes all actions for the given workflow.
     * Returns the persisted execution record.
     *
     * @param array<string, mixed> $context
     */
    public function execute(MusicbotWorkflow $workflow, array $context = []): MusicbotWorkflowExecution
    {
        $execution = new MusicbotWorkflowExecution($workflow, $context);
        $this->entityManager->persist($execution);

        $start = microtime(true);

        if (!$this->evaluator->evaluate($workflow, $context)) {
            $execution->markSkipped('Conditions not met.');
            $workflow->markTriggered();
            $this->entityManager->flush();

            return $execution;
        }

        $execution->markRunning();

        $logLines = [];
        try {
            foreach ($workflow->getActions() as $action) {
                $logLines[] = $this->runAction($action, $workflow->getInstance(), $workflow, $context);
            }

            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $execution->markCompleted(implode("\n", $logLines), $durationMs);

            $this->runtimeEventService->record(
                $workflow->getInstance(),
                'workflow.executed',
                'info',
                sprintf('Workflow "%s" executed (%d action(s)).', $workflow->getName(), count($logLines)),
                ['workflow_id' => $workflow->getId(), 'duration_ms' => $durationMs],
            );
        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $start) * 1000);
            $execution->markFailed($e->getMessage(), implode("\n", $logLines), $durationMs);

            $this->runtimeEventService->record(
                $workflow->getInstance(),
                'workflow.failed',
                'error',
                sprintf('Workflow "%s" failed: %s', $workflow->getName(), $e->getMessage()),
                ['workflow_id' => $workflow->getId()],
            );
        }

        $workflow->markTriggered();
        $this->entityManager->flush();

        return $execution;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function runAction(MusicbotWorkflowAction $action, MusicbotInstance $instance, MusicbotWorkflow $workflow, array $context): string
    {
        return match ($action->getType()) {
            MusicbotWorkflowActionType::CreateRuntimeEvent =>
                $this->handleCreateRuntimeEvent($action, $instance),

            MusicbotWorkflowActionType::SendWebhook =>
                $this->handleSendWebhook($action, $workflow, $context),

            MusicbotWorkflowActionType::TriggerAutoDj =>
                $this->handleTriggerAutoDj($instance),

            default =>
                $this->handleAgentAction($action, $instance, $workflow),
        };
    }

    private function handleCreateRuntimeEvent(MusicbotWorkflowAction $action, MusicbotInstance $instance): string
    {
        $config = $action->getConfig();
        $message = (string) ($config['message'] ?? 'Workflow action executed.');
        $level = in_array($config['level'] ?? '', ['info', 'warning', 'error'], true)
            ? (string) $config['level']
            : 'info';

        $this->runtimeEventService->record($instance, 'workflow.action', $level, $message);

        return sprintf('Runtime event created: [%s] %s', $level, $message);
    }

    private function handleSendWebhook(MusicbotWorkflowAction $action, MusicbotWorkflow $workflow, array $context): string
    {
        $config = $action->getConfig();
        $url = (string) ($config['url'] ?? '');
        $method = strtoupper((string) ($config['method'] ?? 'POST'));

        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH'], true)) {
            $method = 'POST';
        }

        $this->validateWebhookUrl($url);

        $payload = array_merge(
            is_array($config['payload'] ?? null) ? $config['payload'] : [],
            ['workflow_id' => $workflow->getId(), 'workflow_name' => $workflow->getName(), 'trigger_context' => $context],
        );

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $secret = (string) ($config['secret'] ?? '');

        $headers = [
            'Content-Type: application/json',
            'User-Agent: MusicbotWorkflow/1.0',
            'X-Musicbot-Event: workflow.action',
        ];

        if ($secret !== '') {
            $headers[] = 'X-Webhook-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            throw new \RuntimeException(sprintf('Webhook request to "%s" failed.', $url));
        }

        $statusLine = $http_response_header[0] ?? 'Unknown';
        preg_match('/HTTP\/\S+\s+(\d+)/', $statusLine, $m);
        $statusCode = (int) ($m[1] ?? 0);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('Webhook returned HTTP %d for "%s".', $statusCode, $url));
        }

        return sprintf('Webhook sent → %s HTTP %d', $url, $statusCode);
    }

    private function handleTriggerAutoDj(MusicbotInstance $instance): string
    {
        $added = $this->autoDjService->fillQueueForInstance($instance);

        return sprintf('Auto-DJ triggered: %d track(s) added to queue.', $added);
    }

    private function handleAgentAction(MusicbotWorkflowAction $action, MusicbotInstance $instance, MusicbotWorkflow $workflow): string
    {
        $job = $this->jobDispatcher->dispatch($instance->getNode(), 'musicbot.workflow.action', [
            'instance_id' => (string) $instance->getId(),
            'workflow_id' => (string) $workflow->getId(),
            'action_type' => $action->getType()->value,
            'action_config' => $action->getConfig(),
            'service_name' => $instance->getServiceName(),
            'install_path' => $instance->getInstallPath(),
        ]);

        return sprintf('Agent job dispatched: %s → job %s', $action->getType()->value, $job->getId());
    }

    private function validateWebhookUrl(string $url): void
    {
        if ($url === '') {
            throw new \InvalidArgumentException('Webhook URL is required.');
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https') {
            throw new \InvalidArgumentException('Webhook URL must use HTTPS.');
        }

        $host = strtolower(trim((string) ($parsed['host'] ?? ''), '[]'));
        if ($host === '') {
            throw new \InvalidArgumentException('Webhook URL has no host.');
        }

        if ($this->isPrivateHost($host)) {
            throw new \InvalidArgumentException('Webhook URL must not target a private or loopback address.');
        }
    }

    private function isPrivateHost(string $host): bool
    {
        if (in_array($host, ['localhost', '::1', '0.0.0.0', '127.0.0.1'], true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}
