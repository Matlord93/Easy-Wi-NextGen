<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Cms\Application\CmsTemplateDeploymentManager;
use PHPUnit\Framework\TestCase;

final class CmsTemplateDeploymentManagerTest extends TestCase
{
    public function testRollbackSwitchesCurrentToPreviousRelease(): void
    {
        $root = sys_get_temp_dir().'/tpl-deploy-'.bin2hex(random_bytes(4));
        mkdir($root, 0775, true);

        $manager = new CmsTemplateDeploymentManager($root);

        $v1 = $root.'/src-v1';
        $v2 = $root.'/src-v2';
        mkdir($v1, 0775, true);
        mkdir($v2, 0775, true);
        file_put_contents($v1.'/index.html', 'v1');
        file_put_contents($v2.'/index.html', 'v2');

        $manager->deploy('team', $v1);
        usleep(1000);
        $manager->deploy('team', $v2);

        $rollbackTarget = $manager->rollback('team');

        self::assertStringContainsString('/releases/', $rollbackTarget);
        self::assertSame($rollbackTarget, readlink($root.'/team/current'));
    }
}
