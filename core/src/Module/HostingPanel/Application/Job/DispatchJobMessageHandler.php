<?php

declare(strict_types=1);

namespace App\Module\HostingPanel\Application\Job;

use App\Module\HostingPanel\Domain\Entity\Job;
use App\Module\HostingPanel\Domain\Entity\JobRun;
use App\Module\HostingPanel\Domain\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DispatchJobMessageHandler
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(DispatchJobMessage $message): void
    {
        $node = $this->entityManager->find(Node::class, $message->nodeId);
        if (!$node instanceof Node) {
            return;
        }

        $existing = $this->entityManager->getRepository(Job::class)->findOneBy(['idempotencyKey' => $message->idempotencyKey]);
        if ($existing instanceof Job) {
            return;
        }

        $job = new Job($node, $message->type, $message->idempotencyKey, $message->payload);
        $this->entityManager->persist($job);
        $this->entityManager->persist(new JobRun($job));
        $this->entityManager->flush();
    }
}
