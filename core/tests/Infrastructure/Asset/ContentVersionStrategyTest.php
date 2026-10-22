<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Asset;

use App\Infrastructure\Asset\ContentVersionStrategy;
use PHPUnit\Framework\TestCase;

final class ContentVersionStrategyTest extends TestCase
{
    public function testContentChangesInvalidateAssetsEvenWithIdenticalSizeAndTimestamp(): void
    {
        $directory = sys_get_temp_dir().'/easywi-assets-'.bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            file_put_contents($directory.'/app.js', 'old');
            touch($directory.'/app.js', 1000);
            $old = (new ContentVersionStrategy($directory))->applyVersion('/app.js?theme=dark#main');
            file_put_contents($directory.'/app.js', 'new');
            touch($directory.'/app.js', 1000);
            $new = (new ContentVersionStrategy($directory))->applyVersion('/app.js?theme=dark#main');
            self::assertNotSame($old, $new);
            self::assertSame('/app.js?theme=dark&v='.substr(hash('sha256', 'new'), 0, 16).'#main', $new);
            $strategy = new ContentVersionStrategy($directory);
            foreach (['/missing.js', '/../outside', 'https://example.com/app.js', '//example.com/app.js'] as $path) {
                self::assertSame($path, $strategy->applyVersion($path));
            }
        } finally {
            unlink($directory.'/app.js');
            rmdir($directory);
        }
    }
}
