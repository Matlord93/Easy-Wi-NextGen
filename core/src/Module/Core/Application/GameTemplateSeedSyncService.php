<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Template;

final class GameTemplateSeedSyncService
{
    public function __construct(private readonly GameTemplateSeedCatalog $catalog)
    {
    }

    /** @return array<string,mixed>|null */
    public function findSeedTemplate(Template $template): ?array
    {
        $entries = $this->catalog->listTemplates();
        $gameKey = trim($template->getGameKey());
        $steamAppId = $template->getSteamAppId();

        foreach ($entries as $entry) {
            if (($entry['game_key'] ?? '') === $gameKey) {
                return $entry;
            }
        }

        if ($steamAppId !== null) {
            foreach ($entries as $entry) {
                if (($entry['steam_app_id'] ?? null) === $steamAppId && (($entry['game_key'] ?? '') === 'cs2' || $gameKey === 'cs2')) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /** @return array{outdated:bool,current:array<int,array<string,mixed>>,seed:array<int,array<string,mixed>>} */
    public function compareSharedPaths(Template $template): array
    {
        $seed = $this->findSeedTemplate($template);
        $seedPaths = is_array($seed['shared_paths'] ?? null) ? $this->normalizeSharedPaths($seed['shared_paths']) : [];
        $current = $this->normalizeSharedPaths($template->getRequirements()['shared_paths'] ?? []);

        return [
            'outdated' => $seedPaths !== [] && $current !== $seedPaths,
            'current' => $current,
            'seed' => $seedPaths,
        ];
    }

    public function syncSharedPaths(Template $template): bool
    {
        $comparison = $this->compareSharedPaths($template);
        if ($comparison['seed'] === [] || !$comparison['outdated']) {
            return false;
        }

        $requirements = $template->getRequirements();
        $requirements['shared_paths'] = $comparison['seed'];
        $template->setRequirements($requirements);

        return true;
    }

    /** @param mixed $paths @return array<int,array<string,mixed>> */
    private function normalizeSharedPaths(mixed $paths): array
    {
        if (!is_array($paths)) {
            return [];
        }
        $normalized = [];
        foreach ($paths as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $source = trim((string) ($entry['source'] ?? ''));
            $target = trim((string) ($entry['target'] ?? ''));
            if ($source === '' || $target === '') {
                continue;
            }
            $normalized[] = [
                'source' => $source,
                'target' => $target,
                'mode' => (string) ($entry['mode'] ?? 'symlink'),
                'readonly' => (bool) ($entry['readonly'] ?? $entry['read_only'] ?? true),
                'exclude' => array_values(array_filter((array) ($entry['exclude'] ?? []), static fn (mixed $v): bool => is_string($v) && trim($v) !== '')),
            ];
        }
        return $normalized;
    }
}
