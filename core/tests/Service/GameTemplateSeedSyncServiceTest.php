<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\GameTemplateSeedCatalog;
use App\Module\Core\Application\GameTemplateSeedSyncService;
use App\Module\Core\Domain\Entity\Template;
use PHPUnit\Framework\TestCase;

final class GameTemplateSeedSyncServiceTest extends TestCase
{
    public function testCs2SeedContainsSharedTreePath(): void
    {
        $service = new GameTemplateSeedSyncService(new GameTemplateSeedCatalog());
        $template = $this->createTemplate('cs2', 730, [['source' => 'game/bin', 'target' => 'game/bin', 'mode' => 'symlink', 'readonly' => true]]);

        $comparison = $service->compareSharedPaths($template);

        self::assertTrue($comparison['outdated']);
        self::assertSame('shared_tree', $comparison['seed'][1]['mode']);
        self::assertSame(['cfg', 'gameinfo.gi', 'gameinfo_branchspecific.gi'], $comparison['seed'][1]['exclude']);
    }

    public function testSyncUpdatesOnlySharedPaths(): void
    {
        $service = new GameTemplateSeedSyncService(new GameTemplateSeedCatalog());
        $template = $this->createTemplate('cs2', 730, [['source' => 'game/bin', 'target' => 'game/bin', 'mode' => 'symlink', 'readonly' => true]]);
        $template->setInstallCommand('custom install');

        $changed = $service->syncSharedPaths($template);

        self::assertTrue($changed);
        self::assertSame('custom install', $template->getInstallCommand());
        $shared = $template->getRequirements()['shared_paths'] ?? [];
        self::assertCount(3, $shared);
        self::assertSame('game/core', $shared[0]['source']);
        self::assertSame('shared_tree', $shared[1]['mode']);
    }

    private function createTemplate(string $gameKey, ?int $steamAppId, array $sharedPaths): Template
    {
        return new Template($gameKey, 'Test', null, $steamAppId, 'steam', [], 'run', [], [], [], [], 'install', 'update', [], [], [], [], ['linux'], [], ['shared_paths' => $sharedPaths]);
    }
}
