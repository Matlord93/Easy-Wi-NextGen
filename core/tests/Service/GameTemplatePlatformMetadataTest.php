<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\GameTemplateSeedCatalog;
use PHPUnit\Framework\TestCase;

final class GameTemplatePlatformMetadataTest extends TestCase
{
    public function testWindroseLinuxRestrictsThePlatformWithoutAnInvalidInstallResolver(): void
    {
        $templates = array_column((new GameTemplateSeedCatalog())->listTemplates(), null, 'game_key');

        self::assertSame(['linux'], $templates['windrose']['supported_os']);
        self::assertSame([], $templates['windrose']['install_resolver']);
    }
}
