<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\MailAlias;

final class MailAliasLoopGuard
{
    /**
     * @param MailAlias[] $existingAliases
     * @param string[] $destinations
     */
    public function wouldCreateLoop(string $sourceAddress, array $destinations, array $existingAliases): bool
    {
        $graph = [];
        foreach ($existingAliases as $alias) {
            $graph[strtolower($alias->getAddress())] = array_values(array_unique(array_map('strtolower', $alias->getDestinations())));
        }
        $graph[strtolower($sourceAddress)] = array_values(array_unique(array_map('strtolower', $destinations)));

        $target = strtolower($sourceAddress);
        $queue = new \SplQueue();
        foreach (($graph[$target] ?? []) as $destination) {
            $queue->enqueue($destination);
        }
        $visited = [];

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            if ($current === $target) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            if (isset($graph[$current])) {
                foreach ($graph[$current] as $next) {
                    if (!isset($visited[$next])) {
                        $queue->enqueue($next);
                    }
                }
            }
        }

        return false;
    }
}
