<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Gameserver\Application\MinecraftCatalogSynchronizer;
use App\Module\Gameserver\Application\MinecraftUpstreamCatalog;
use App\Repository\MinecraftVersionCatalogRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class MinecraftCatalogSynchronizerTest extends TestCase
{
    public function testDispatchRunsCliWorkerOnceAndQuotesReleasePaths(): void
    {
        $root = sys_get_temp_dir().'/minecraft worker '.bin2hex(random_bytes(8));
        $directory = $root.'/var/minecraft-catalog';
        mkdir($directory, 0700, true);
        mkdir($root.'/bin', 0700);
        file_put_contents($root.'/bin/console', '<?php file_put_contents(dirname(__DIR__)."/worker.json", json_encode(["sapi" => PHP_SAPI, "args" => $argv]), LOCK_EX);');
        try {
            $synchronizer = new MinecraftCatalogSynchronizer(
                new MinecraftUpstreamCatalog(new MockHttpClient(), new ArrayAdapter()),
                new MinecraftVersionCatalogRepository($this->createStub(\Doctrine\Persistence\ManagerRegistry::class)),
                $this->createStub(EntityManagerInterface::class),
                $directory,
                phpCliBinary: PHP_BINARY,
            );
            $synchronizer->startIfDue();
            for ($attempt = 0; $attempt < 100 && !is_file($root.'/worker.json'); ++$attempt) { usleep(10000); }
            self::assertFileExists($root.'/worker.json');
            $worker = json_decode((string) file_get_contents($root.'/worker.json'), true);
            self::assertSame('cli', $worker['sapi']);
            self::assertSame('catalog:sync:minecraft', $worker['args'][1]);
            self::assertTrue($synchronizer->status()['queued']);
            unlink($root.'/worker.json');
            $synchronizer->startIfDue();
            usleep(50000);
            self::assertFileDoesNotExist($root.'/worker.json');
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    public function testQueuedRefreshOverridesPreviousSuccessfulStatus(): void
    {
        $directory = sys_get_temp_dir().'/minecraft-sync-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            file_put_contents($directory.'/status.json', json_encode(['queued' => false, 'attempted_at' => time()]));
            $synchronizer = new MinecraftCatalogSynchronizer(
                new MinecraftUpstreamCatalog(new MockHttpClient(), new ArrayAdapter()),
                new MinecraftVersionCatalogRepository($this->createStub(\Doctrine\Persistence\ManagerRegistry::class)),
                $this->createStub(EntityManagerInterface::class),
                $directory,
            );
            self::assertFalse($synchronizer->status()['queued']);
            $synchronizer->requestRefresh();
            self::assertTrue($synchronizer->status()['queued']);
            unlink($directory.'/refresh');
            self::assertFalse($synchronizer->status()['queued']);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
