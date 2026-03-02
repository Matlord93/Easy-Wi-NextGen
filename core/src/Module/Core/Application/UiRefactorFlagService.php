<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class UiRefactorFlagService
{
    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        $flags = [
            'nodes' => false,
            'instances' => false,
            'webspace' => false,
            'mail' => false,
            'voice' => false,
            'ops' => false,
        ];

        foreach ($this->csvFlags() as $key) {
            if (array_key_exists($key, $flags)) {
                $flags[$key] = true;
            }
        }

        foreach (array_keys($flags) as $key) {
            $flags[$key] = $flags[$key] || $this->envBool(sprintf('APP_UI_REFACTOR_%s', strtoupper($key)));
        }

        return $flags;
    }

    public function isEnabled(string $domain): bool
    {
        $flags = $this->all();

        return $flags[$domain] ?? false;
    }

    /**
     * @return list<string>
     */
    private function csvFlags(): array
    {
        $raw = trim((string) ($this->readEnv('APP_UI_REFACTOR_FLAGS') ?? ''));
        if ($raw === '') {
            return [];
        }

        $items = array_map(
            static fn (string $entry): string => strtolower(trim($entry)),
            explode(',', $raw),
        );

        return array_values(array_filter(array_unique($items), static fn (string $entry): bool => $entry !== ''));
    }

    private function envBool(string $name): bool
    {
        $raw = strtolower(trim((string) ($this->readEnv($name) ?? '')));

        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    private function readEnv(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }
}

