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
