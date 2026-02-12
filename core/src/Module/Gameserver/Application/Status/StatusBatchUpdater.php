<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Status;

use App\Repository\InstanceRepository;
use Doctrine\ORM\EntityManagerInterface;

final class StatusBatchUpdater
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function apply(array $items): int
    {
        $ids = [];
        foreach ($items as $item) {
            if (is_numeric($item['server_id'] ?? null)) {
                $ids[] = (int) $item['server_id'];
            }
        }

        if ($ids === []) {
            return 0;
        }

        $instances = $this->instanceRepository->findBy(['id' => array_values(array_unique($ids))]);
        $indexed = [];
        foreach ($instances as $instance) {
            $indexed[(int) $instance->getId()] = $instance;
        }

        $updated = 0;
        foreach ($items as $item) {
            $instanceId = is_numeric($item['server_id'] ?? null) ? (int) $item['server_id'] : null;
            if ($instanceId === null || !isset($indexed[$instanceId])) {
                continue;
            }

            $instance = $indexed[$instanceId];
            $cache = $instance->getQueryStatusCache();
            $cache['reachable'] = (bool) ($item['reachable'] ?? false);
            $cache['status'] = is_string($item['status'] ?? null) ? strtolower((string) $item['status']) : 'offline';
            $cache['players'] = is_numeric($item['players_online'] ?? null) ? (int) $item['players_online'] : null;
            $cache['max_players'] = is_numeric($item['players_max'] ?? null) ? (int) $item['players_max'] : null;
            $cache['map'] = is_string($item['map'] ?? null) && trim((string) $item['map']) !== '' ? (string) $item['map'] : null;
            $cache['latency_ms'] = is_numeric($item['latency_ms'] ?? null) ? (int) $item['latency_ms'] : null;
            $cache['error'] = is_string($item['error'] ?? null) ? (string) $item['error'] : null;
            $cache['raw'] = is_array($item['raw'] ?? null) ? $item['raw'] : null;

            $observedAt = $this->parseDateTime($item['observed_at'] ?? null) ?? new \DateTimeImmutable();
            $cache['observed_at'] = $observedAt->format(DATE_ATOM);

            $instance->setQueryStatusCache($cache);
            $instance->setQueryCheckedAt($observedAt);
            $this->entityManager->persist($instance);
            $updated++;
        }

        if ($updated > 0) {
            $this->entityManager->flush();
        }

        return $updated;
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
