<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\GameTemplateSeedCatalog;
use App\Module\Core\Application\GameTemplateSeedSyncService;
use App\Module\Core\Domain\Entity\Template;
use PHPUnit\Framework\TestCase;

final class GameTemplateSeedSyncServiceTest extends TestCase
{
    public function testCs2SeedContainsOverlayPath(): void
    {
        $service = new GameTemplateSeedSyncService(new GameTemplateSeedCatalog());
        $template = $this->createTemplate('cs2', 730, [['source' => 'game/bin', 'target' => 'game/bin', 'mode' => 'symlink', 'readonly' => true]]);

        $comparison = $service->compareSharedPaths($template);

        self::assertTrue($comparison['outdated']);
        self::assertSame('overlay', $comparison['seed'][3]['mode']);
        self::assertSame(['cfg', 'gameinfo.gi', 'gameinfo_branchspecific.gi'], $comparison['seed'][3]['exclude']);
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
        self::assertCount(9, $shared);
        self::assertSame('game/bin', $shared[0]['source']);
        self::assertSame('overlay', $shared[3]['mode']);
    }

    public function testWindroseLinuxTemplateUsesWineXvfbTasksetCommand(): void
    {
        $catalog = new GameTemplateSeedCatalog();
        $template = null;
        foreach ($catalog->listTemplates() as $candidate) {
            if (($candidate['display_name'] ?? null) === 'Windrose Dedicated Server (Linux via Wine)') {
                $template = $candidate;
                break;
            }
        }

        self::assertNotNull($template);
        $startParams = $template['start_params'];
        self::assertStringContainsString('cd {{INSTANCE_DIR}}', $startParams);
        self::assertStringContainsString('xvfb-run --auto-servernum', $startParams);
        self::assertStringContainsString("--server-args='-screen 0 1024x768x24'", $startParams);
        self::assertStringContainsString('WINE_NO_STRICT_PROT=1', $startParams);
        self::assertStringContainsString('taskset -c 0-11', $startParams);
        self::assertStringContainsString('wine R5/Binaries/Win64/WindroseServer-Win64-Shipping.exe', $startParams);
        self::assertStringContainsString('-nullrhi -log', $startParams);
    }

    private function createTemplate(string $gameKey, ?int $steamAppId, array $sharedPaths): Template
    {
        return new Template($gameKey, 'Test', null, $steamAppId, 'steam', [], 'run', [], [], [], [], 'install', 'update', [], [], [], [], ['linux'], [], ['shared_paths' => $sharedPaths]);
    }
}
