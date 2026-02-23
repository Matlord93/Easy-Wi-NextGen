<?php

declare(strict_types=1);

namespace App\Module\Gameserver\Application\Query;

use App\Module\Core\Domain\Entity\Template;

final class TemplateEngineFamilyResolver
{
    public function resolve(Template $template): string
    {
        $requirements = $template->getRequirements();
        $query = is_array($requirements['query'] ?? null) ? $requirements['query'] : [];
        $rawProtocol = (string) ($query['type'] ?? $requirements['query_type'] ?? '');
        $protocol = strtolower(trim($rawProtocol));
        $gameKey = strtolower(trim($template->getGameKey()));

        if (in_array($protocol, ['minecraft_bedrock', 'bedrock', 'mcpe'], true) || str_contains($gameKey, 'bedrock')) {
            return 'bedrock';
        }

        if (in_array($protocol, ['minecraft_java', 'minecraft', 'java'], true) || str_contains($gameKey, 'minecraft')) {
            return 'minecraft_java';
        }

        if ($gameKey === 'cs2' || str_starts_with($gameKey, 'cs2_') || str_contains($gameKey, '_cs2') || str_contains($gameKey, 'source2')) {
            return 'source2';
        }

        return 'source1';
    }
}
