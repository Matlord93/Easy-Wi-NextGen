<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Mail\Queue;

use App\Message\MailControlPlaneJobMessage;
use App\Module\AgentOrchestrator\Application\AgentJobDispatcher;
use App\Repository\AgentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class MailControlPlaneJobMessageHandler
{
    public function __construct(
        private AgentRepository $agentRepository,
        private AgentJobDispatcher $agentJobDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(MailControlPlaneJobMessage $message): void
    {
        $node = $this->agentRepository->find($message->nodeId);
        if ($node === null) {
            throw new \RuntimeException(sprintf('Node %s not found for mail control-plane job.', $message->nodeId));
        }

        $payload = $message->payload;
        $payload['correlation_id'] = $message->correlationId;
        $payload['idempotency_key'] = $message->idempotencyKey;

        $job = $this->agentJobDispatcher->dispatchWithFailureLogging($node, $message->type->value, $payload);

        $this->logger->info('mail.control_plane.job_dispatched', [
            'mail_job_type' => $message->type->value,
            'agent_job_id' => $job->getId(),
            'node_id' => $message->nodeId,
            'correlation_id' => $message->correlationId,
            'idempotency_key' => $message->idempotencyKey,
        ]);
    }
}
