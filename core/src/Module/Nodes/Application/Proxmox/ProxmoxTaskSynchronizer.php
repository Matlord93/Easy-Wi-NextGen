<?php

declare(strict_types=1);

namespace App\Module\Nodes\Application\Proxmox;

use App\Module\Nodes\Domain\Entity\ProxmoxTask;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class ProxmoxTaskSynchronizer
{
    public function __construct(private EntityManagerInterface $em, private ProxmoxManager $manager, private LockFactory $locks)
    {
    }

    /** @return array{checked:int,errors:int} */
    public function run(int $limit): array
    {
        $lock = $this->locks->createLock('proxmox.tasks.sync', 120);
        if (!$lock->acquire()) {
            return ['checked' => 0, 'errors' => 0];
        }
        try {
            $query = $this->em->createQueryBuilder()->select('task, CASE WHEN task.lastPolledAt IS NULL THEN 0 ELSE 1 END AS HIDDEN wasPolled')
                ->from(ProxmoxTask::class, 'task')->join('task.cluster', 'cluster')->where('task.state IN (:states)')
                ->andWhere('cluster.enabled = true')->setParameter('states', ['queued', 'submitting', 'running'])
                ->orderBy('wasPolled', 'ASC')->addOrderBy('task.lastPolledAt', 'ASC')->addOrderBy('task.id', 'ASC')->setMaxResults($limit)->getQuery();
            $tasks = \App\Repository\EntityQueryResult::all($query, ProxmoxTask::class);
            $checked = $errors = 0;
            $deadline = microtime(true) + 25.0;
            foreach ($tasks as $task) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                $task->polled(new \DateTimeImmutable());
                $this->em->flush();
                try {
                    $this->manager->refreshTask($task);
                    ++$checked;
                } catch (\Throwable) {
                    ++$errors;
                }
            }

            return compact('checked', 'errors');
        } finally {
            $lock->release();
        }
    }
}
