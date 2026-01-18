<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\Application;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Module\Core\Domain\Entity\Agent;
use Symfony\Component\Uid\Uuid;

final class AgentJobFactory
{
    public function create(Agent $node, string $type, array $payload, ?string $idempotencyKey = null): AgentJob
    {
        $jobId = Uuid::v4()->toRfc4122();
        $job = new AgentJob($jobId, $node, $type, $payload);
        $job->setIdempotencyKey($idempotencyKey);

        return $job;
    }
}
