<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\GameTemplateSeeder;
use App\Module\Core\Application\MinecraftVersionImportService;
use App\Module\Core\Domain\Entity\MinecraftVersionCatalog;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Gameserver\Application\MinecraftCatalogService;
use App\Module\Gameserver\Application\MinecraftCatalogSynchronizer;
use App\Module\Gameserver\Application\MinecraftJavaVersionResolver;
use App\Module\Gameserver\Application\MinecraftUpstreamCatalog;
use App\Repository\MinecraftVersionCatalogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MinecraftAutomaticCatalogTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MinecraftVersionCatalogRepository $repository;
    private string $directory;
    private array $environment = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        foreach (['DATABASE_URL' => 'sqlite:///:memory:', 'EASYWI_DB_CONFIG_PATH' => '/tmp/easywi-minecraft-test-no-config.json'] as $key => $value) {
            $this->environment[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->repository = self::getContainer()->get(MinecraftVersionCatalogRepository::class);
        $this->directory = sys_get_temp_dir().'/minecraft-auto-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        // A recent check prevents launching a real background process in this test.
        file_put_contents($this->directory.'/status.json', json_encode(['attempted_at' => time(), 'channels' => ['vanilla' => ['success_at' => time()]]]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        self::ensureKernelShutdown();
        foreach ($this->environment as $key => [$server, $env]) {
            if (null === $server) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if (null === $env) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
        parent::tearDown();
    }

    public function testFirstSelectionAutomaticallySeedsTemplatesAndVersionsWithoutNetwork(): void
    {
        $sync = $this->synchronizer(self::getContainer()->get(MinecraftVersionImportService::class));
        $catalog = new MinecraftCatalogService($this->repository, null, $sync);
        self::assertSame(0, $this->repository->count([]));
        $ui = $catalog->getUiCatalog();
        foreach (['vanilla', 'paper', 'bedrock'] as $channel) {
            self::assertNotEmpty($ui[$channel]['versions'], $channel);
            self::assertNotNull($catalog->resolveEntry($channel, null, null), $channel);
            $stable = $catalog->resolveVersion($channel, null);
            self::assertSame('release', $ui[$channel]['release_types'][$stable]);
        }
        self::assertSame(5, $this->em->getRepository(Template::class)->count([]));
        $count = $this->repository->count([]);
        $entry = $catalog->resolveEntry('vanilla', null, null);
        $entry->setIsActive(false);
        $template = $this->em->getRepository(Template::class)->findOneBy(['gameKey' => 'minecraft_vanilla_all']);
        $template->setStartParams('custom-start-command');
        $this->em->flush();
        // A later request must neither duplicate nor reactivate disabled versions.
        (new MinecraftCatalogService($this->repository, null, $this->synchronizer(self::getContainer()->get(MinecraftVersionImportService::class))))->getUiCatalog();
        self::assertSame($count, $this->repository->count([]));
        self::assertFalse($entry->isActive());
        self::assertSame('custom-start-command', $template->getStartParams());
    }

    public function testMissingImportSourceDoesNotDeactivateExistingVersions(): void
    {
        $entry = new MinecraftVersionCatalog('vanilla', '1.21.11', null, 'https://example.test/server.jar');
        $entry->setSource('import');
        $this->em->persist($entry);
        $this->em->flush();
        $missing = $this->directory.'/missing.json';
        $importer = new MinecraftVersionImportService($this->repository, $this->em, new MinecraftJavaVersionResolver(), self::getContainer()->get(TranslatorInterface::class), $missing, $missing, $missing);
        $result = $importer->import(['vanilla'], false, false, true);
        self::assertNotEmpty($result['errors']);
        self::assertSame(0, $result['deactivated']);
        self::assertTrue($entry->isActive());
    }

    public function testProviderOutagesKeepCatalogAndDoNotExposeInternalErrors(): void
    {
        $entry = new MinecraftVersionCatalog('vanilla', '1.21.11', null, 'https://example.test/server.jar');
        $this->em->persist($entry);
        $this->em->flush();
        $sync = $this->synchronizer();
        $status = $sync->synchronize(true);
        self::assertNotEmpty($status['channels']['vanilla']['error']);
        self::assertSame($entry, (new MinecraftCatalogService($this->repository))->resolveEntry('vanilla', '1.21.11', null));
        self::assertSame(1, $this->repository->count([]));
        self::assertSame(['updated_at', 'unavailable'], array_keys($sync->publicStatus()['channels']['vanilla']));
        self::assertTrue($sync->publicStatus()['channels']['vanilla']['unavailable']);
        self::assertFalse($sync->publicStatus()['updating']);
    }

    public function testRunningWorkerPreventsDuplicateDispatch(): void
    {
        $sync = $this->synchronizer();
        $sync->requestRefresh();
        $lock = fopen($this->directory.'/sync.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            self::assertTrue($sync->publicStatus()['updating']);
            $sync->startIfDue();
            self::assertFileDoesNotExist($this->directory.'/dispatched');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function synchronizer(?MinecraftVersionImportService $importer = null): MinecraftCatalogSynchronizer
    {
        return new MinecraftCatalogSynchronizer(
            new MinecraftUpstreamCatalog(new MockHttpClient(static fn () => new MockResponse('unavailable', ['http_code' => 503])), new ArrayAdapter()),
            $this->repository, $this->em, $this->directory,
            self::getContainer()->get(GameTemplateSeeder::class), $importer,
        );
    }
}
