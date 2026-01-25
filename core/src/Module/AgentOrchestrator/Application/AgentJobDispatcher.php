<?php

declare(strict_types=1);

namespace App\Module\AgentOrchestrator\Application;

use App\Module\AgentOrchestrator\Domain\Entity\AgentJob;
use App\Repository\AgentJobRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AgentJobDispatcher
{
    public function __construct(
        private readonly AgentJobFactory $factory,
        private readonly AgentJobValidator $validator,
        private readonly AgentJobRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(\App\Module\Core\Domain\Entity\Agent $node, string $type, array $payload): AgentJob
    {
        $errors = $this->validator->validate($type, $payload);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $idempotencyKey = $this->buildIdempotencyKey($node->getId(), $type, $payload);
        $existing = $this->repository->findLatestByIdempotencyKey($idempotencyKey);
        if ($existing instanceof AgentJob && $existing->getStatus()->value !== 'failed') {
            return $existing;
        }

        $job = $this->factory->create($node, $type, $payload, $idempotencyKey);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchWithFailureLogging(\App\Module\Core\Domain\Entity\Agent $node, string $type, array $payload): AgentJob
    {
        try {
            return $this->dispatch($node, $type, $payload);
        } catch (\InvalidArgumentException $exception) {
            $idempotencyKey = $this->buildIdempotencyKey($node->getId(), $type, $payload);
            $existing = $this->repository->findLatestByIdempotencyKey($idempotencyKey);
            if ($existing instanceof AgentJob) {
                return $existing;
            }

            $job = $this->factory->create($node, $type, $payload, $idempotencyKey);
            $job->setErrorText($exception->getMessage());
            $job->markFinished(\App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Failed);

            $this->entityManager->persist($job);
            $this->entityManager->flush();

            return $job;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildIdempotencyKey(string $nodeId, string $type, array $payload): string
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return hash('sha256', $nodeId . ':' . $type . ':' . $payloadJson);
    }
}
