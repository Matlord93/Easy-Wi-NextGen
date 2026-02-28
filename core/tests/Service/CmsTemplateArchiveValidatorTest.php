<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Cms\Application\CmsTemplateArchiveValidator;
use PHPUnit\Framework\TestCase;

final class CmsTemplateArchiveValidatorTest extends TestCase
{
    public function testRejectsPathTraversalEntries(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'tpl').'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('../evil.php', 'bad');
        $zip->close();

        $validator = new CmsTemplateArchiveValidator();

        $this->expectException(\InvalidArgumentException::class);
        $validator->validate($zipPath);
    }
}
