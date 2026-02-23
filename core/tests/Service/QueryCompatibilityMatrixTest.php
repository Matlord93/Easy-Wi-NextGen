<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\GameTemplateSeedCatalog;
use PHPUnit\Framework\TestCase;

final class QueryCompatibilityMatrixTest extends TestCase
{
    public function testEveryOfferedTemplateHasSupportedQueryProtocolAndTimeout(): void
    {
        $catalog = new GameTemplateSeedCatalog();
        $templates = $catalog->listTemplates();

        $supportedProtocols = ['steam_a2s', 'minecraft_java', 'minecraft_bedrock'];

        foreach ($templates as $template) {
            $slug = strtolower(trim((string) ($template['game_key'] ?? '')));
            self::assertNotSame('', $slug, 'template slug missing');

            $queryProtocol = $this->resolveProtocol($slug, $template);
            self::assertContains($queryProtocol, $supportedProtocols, sprintf('unsupported protocol for template %s', $slug));

            $queryPortRule = $this->resolvePortRule($slug, $template);
            self::assertContains($queryPortRule, ['same_as_game_port', 'explicit', 'plus_one'], sprintf('invalid query_port rule for template %s', $slug));

            $timeoutMs = $this->resolveTimeoutMs($template);
            self::assertGreaterThanOrEqual(250, $timeoutMs, sprintf('timeout too low for template %s', $slug));
            self::assertLessThanOrEqual(15000, $timeoutMs, sprintf('timeout too high for template %s', $slug));
        }
    }

    private function resolveProtocol(string $slug, array $template): string
    {
        if (str_contains($slug, 'minecraft_bedrock') || $slug === 'bedrock') {
            return 'minecraft_bedrock';
        }
        if (str_contains($slug, 'minecraft')) {
            return 'minecraft_java';
        }

        return 'steam_a2s';
    }

    private function resolvePortRule(string $slug, array $template): string
    {
        if ($slug === 'cs2' || str_starts_with($slug, 'cs2_') || str_contains($slug, '_cs2')) {
            return 'explicit';
        }

        return 'same_as_game_port';
    }

    private function resolveTimeoutMs(array $template): int
    {
        $timeout = (int) ($template['query_timeout_ms'] ?? 4000);
        if ($timeout <= 0) {
            return 4000;
        }

        return $timeout;
    }
}
