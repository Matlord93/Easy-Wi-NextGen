<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Setup\Application\WebinterfaceUpdateService;
use App\Module\Setup\Application\WebinterfaceUpdateSettingsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Process\Process;

final class WebinterfaceUpdateActivationTest extends TestCase
{
    private string $directory;
    private ?Process $server = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/easywi-activation-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        (new Filesystem())->remove($this->directory);
    }

    private function app(string $path, string $version): void
    {
        foreach (['public', 'bin', 'var/log', 'srv/update/jobs', 'uploads', 'storage'] as $dir) {
            mkdir($path.'/'.$dir, 0775, true);
        }
        file_put_contents($path.'/VERSION', $version);
        file_put_contents($path.'/public/index.php', '<?php if (is_file(dirname(__DIR__)."/var/update-maintenance.flag")) {http_response_code(503);echo "maintenance";return;} echo '.var_export($version, true).';');
        file_put_contents($path.'/bin/console', '<?php exit(is_file(dirname(__DIR__)."/fail-postdeploy") ? 7 : 0);');
    }

    private function service(string $active, string $configured = 'unused', ?\App\Repository\AgentJobRepository $repository = null, ?MockHttpClient $client = null): WebinterfaceUpdateService
    {
        return new WebinterfaceUpdateService(
            httpClient: $client ?? new MockHttpClient(), settingsService: new WebinterfaceUpdateSettingsService($active),
            manifestUrl: 'https://localhost/manifest.json', installDir: $this->directory.'/'.$configured, releasesDir: $this->directory.'/releases',
            currentSymlink: $this->directory.'/current', lockFile: $this->directory.'/update.lock', excludes: '',
            fallbackVersion: '1.0.0', releaseRepository: 'Matlord93/webinterface', releaseChannel: 'stable',
            kernelEnvironment: 'test', kernelDebug: false, phpCliBinary: PHP_BINARY, keepReleases: 2, projectDir: $active, agentJobRepository: $repository,
        );
    }

    public function testReloadWaitsForSuccessAndReportsFailuresMissingJobsAndTimeouts(): void
    {
        $active = $this->directory.'/panel';
        $this->app($active, '2.0.0');
        $job = (new \ReflectionClass(\App\Module\AgentOrchestrator\Domain\Entity\AgentJob::class))->newInstanceWithoutConstructor();
        $status = new \ReflectionProperty($job, 'status');
        $created = new \ReflectionProperty($job, 'createdAt');
        $status->setValue($job, \App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Queued);
        $created->setValue($job, new \DateTimeImmutable());
        $manager = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn(new \Doctrine\ORM\Mapping\ClassMetadata($job::class));
        $manager->method('find')->willReturnCallback(static fn ($class, $id) => 'missing' === $id ? null : $job);
        $registry = $this->createStub(\Doctrine\Persistence\ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $service = $this->service($active, repository: new \App\Repository\AgentJobRepository($registry));
        self::assertTrue($service->awaitAgentReload('reload')->pending);
        $status->setValue($job, \App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Success);
        self::assertTrue($service->awaitAgentReload('reload')->success);
        self::assertFalse($service->awaitAgentReload('reload')->pending);
        $status->setValue($job, \App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Failed);
        self::assertSame('reload_failed', $service->awaitAgentReload('reload')->error);
        self::assertSame('reload_job_missing', $service->awaitAgentReload('missing')->error);
        $status->setValue($job, \App\Module\AgentOrchestrator\Domain\Enum\AgentJobStatus::Queued);
        $created->setValue($job, new \DateTimeImmutable('-6 minutes'));
        self::assertSame('reload_timeout', $service->awaitAgentReload('reload')->error);
    }

    public function testCliCannotReportAnActivatedUpdateWithoutAWebReloadAgent(): void
    {
        $active = $this->directory.'/panel';
        $this->app($active, '1.0.0');
        $client = new MockHttpClient([new \Symfony\Component\HttpClient\Response\MockResponse(json_encode([
            'latest' => '2.0.0',
            'asset_url' => 'https://github.com/Matlord93/webinterface/releases/download/v2.0.0/easywi-core.tar.gz',
            'sha256' => str_repeat('a', 64),
        ], JSON_THROW_ON_ERROR))]);
        $result = $this->service($active, client: $client)->applyUpdate();
        self::assertFalse($result->success);
        self::assertSame('web_reload_required', $result->error);
        self::assertSame('1.0.0', trim(file_get_contents($active.'/VERSION')));
        self::assertSame(1, $client->getRequestsCount());
    }

    private function deploy(WebinterfaceUpdateService $service, string $source): bool
    {
        return (new \ReflectionMethod($service, 'deployUpdate'))->invoke($service, $source, '2.0.0', $this->directory.'/update.log');
    }

    private function startServer(string $public, ?string $updateSource = null): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $router = $this->directory.'/router.php';
        $code = '<?php ';
        if (null !== $updateSource) {
            $arguments = [
                'manifestUrl' => '', 'installDir' => dirname($public), 'releasesDir' => '', 'currentSymlink' => '',
                'lockFile' => $this->directory.'/update.lock', 'excludes' => '', 'fallbackVersion' => '1.0.0',
                'releaseRepository' => 'Matlord93/webinterface', 'releaseChannel' => 'stable', 'kernelEnvironment' => 'test',
                'kernelDebug' => false, 'phpCliBinary' => PHP_BINARY, 'projectDir' => dirname($public),
            ];
            $constructor = 'httpClient:new \\Symfony\\Component\\HttpClient\\MockHttpClient(),settingsService:new \\App\\Module\\Setup\\Application\\WebinterfaceUpdateSettingsService('.var_export(dirname($public), true).')';
            foreach ($arguments as $name => $value) {
                $constructor .= ','.$name.':'.var_export($value, true);
            }
            $code .= 'if($_SERVER["REQUEST_URI"]==="/cache"){echo json_encode(opcache_get_status(false));return;}';
            $code .= 'if($_SERVER["REQUEST_URI"]==="/activate"){require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';$service=new \\App\\Module\\Setup\\Application\\WebinterfaceUpdateService('.$constructor.');echo (new ReflectionMethod($service,"deployUpdate"))->invoke($service,'.var_export($updateSource, true).',"2.0.0",'.var_export($this->directory.'/update.log', true).')?"activated":"failed";return;}';
        }
        file_put_contents($router, $code.'clearstatcache(true); require '.var_export($public.'/index.php', true).';');
        $this->server = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'opcache.enable='.(null === $updateSource ? '0' : '1'), '-d', 'opcache.enable_cli='.(null === $updateSource ? '0' : '1'), '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0', '-S', $address, $router], $this->directory);
        $this->server->start();
        $url = 'http://'.$address.'/';
        for ($i = 0; $i < 100; ++$i) {
            if (false !== @file_get_contents($url)) {
                return $url;
            }
            if (!$this->server->isRunning()) {
                self::fail($this->server->getErrorOutput());
            }
            usleep(20000);
        }
        self::fail('Local activation test server did not start.');
    }

    public function testDirectInstallationUpdatesServedFilesInsteadOfCreatingUnusedCurrentLink(): void
    {
        $active = $this->directory.'/panel';
        $source = $this->directory.'/package';
        $this->app($active, '1.0.0');
        $this->app($source, '2.0.0');
        $url = $this->startServer($active.'/public');
        self::assertSame('1.0.0', file_get_contents($url));
        self::assertTrue($this->deploy($this->service($active), $source), file_get_contents($this->directory.'/update.log'));
        self::assertSame('2.0.0', file_get_contents($url));
        self::assertFalse(is_link($this->directory.'/current'));
        self::assertDirectoryDoesNotExist($this->directory.'/unused');
    }

    public function testUnusedStaleCurrentLinkCannotOverrideActiveVersionOrMigrationTarget(): void
    {
        $active = $this->directory.'/panel';
        $stale = $this->directory.'/inactive';
        $source = $this->directory.'/package';
        $this->app($active, '1.0.0');
        $this->app($stale, '9.9.9');
        $this->app($source, '2.0.0');
        symlink($stale, $this->directory.'/current');
        $service = $this->service($active);
        self::assertSame('1.0.0', (new \ReflectionMethod($service, 'getInstalledVersion'))->invoke($service));
        self::assertSame($active, (new \ReflectionMethod($service, 'resolveCurrentDir'))->invoke($service));
        self::assertTrue($this->deploy($service, $source));
        self::assertSame('9.9.9', file_get_contents($stale.'/VERSION'));
        self::assertSame('2.0.0', (new \ReflectionMethod($service, 'getInstalledVersion'))->invoke($service));
    }

    public function testRepositoryArchiveTargetsTheActiveApplicationWithoutNestingCoreAgain(): void
    {
        $active = $this->directory.'/panel';
        $package = $this->directory.'/package';
        $this->app($active, '1.0.0');
        $this->app($package.'/core', '2.0.0');
        $this->app($active.'/core', 'stale-nested-copy');
        $url = $this->startServer($active.'/public');
        self::assertTrue($this->deploy($this->service($active), $package));
        self::assertSame('2.0.0', file_get_contents($url));
        self::assertFileDoesNotExist($active.'/core/public/index.php');
    }

    public function testExistingActiveSymlinkSwitchesServedReleaseAndKeepsJobsAndUploadsShared(): void
    {
        $old = $this->directory.'/releases/1.0.0';
        $source = $this->directory.'/package';
        $this->app($old, '1.0.0');
        $this->app($source, '2.0.0');
        symlink($old, $this->directory.'/current');
        file_put_contents($old.'/srv/update/jobs/job.json', 'running');
        file_put_contents($old.'/uploads/world.txt', 'keep');
        $url = $this->startServer($this->directory.'/current/public');
        self::assertSame('1.0.0', file_get_contents($url));
        self::assertTrue($this->deploy($this->service($old), $source), file_get_contents($this->directory.'/update.log'));
        $new = realpath($this->directory.'/current');
        self::assertNotSame($old, $new);
        self::assertSame('2.0.0', file_get_contents($url));
        self::assertSame(realpath($old.'/srv'), realpath($new.'/srv'));
        file_put_contents($old.'/srv/update/jobs/job.json', 'success');
        self::assertSame('success', file_get_contents($new.'/srv/update/jobs/job.json'));
        self::assertSame('keep', file_get_contents($new.'/uploads/world.txt'));
        self::assertFileDoesNotExist($new.'/var/update-maintenance.flag');
    }

    public function testPostDeployFailureRestoresServedCodeAndPreservesSecretsAndUploads(): void
    {
        $active = $this->directory.'/panel';
        $source = $this->directory.'/package';
        $this->app($active, '1.0.0');
        $this->app($source, '2.0.0');
        file_put_contents($source.'/fail-postdeploy', '1');
        file_put_contents($active.'/.env.local', 'private local config');
        file_put_contents($active.'/uploads/customer.txt', 'keep');
        $url = $this->startServer($active.'/public');
        self::assertFalse($this->deploy($this->service($active), $source));
        self::assertSame('1.0.0', file_get_contents($url));
        self::assertSame('private local config', file_get_contents($active.'/.env.local'));
        self::assertSame('keep', file_get_contents($active.'/uploads/customer.txt'));
        self::assertFileDoesNotExist($active.'/var/update-maintenance.flag');
    }

    public function testFailedSymlinkPostDeployKeepsOldReleaseAndRuntimeAccessible(): void
    {
        $old = $this->directory.'/releases/1.0.0';
        $source = $this->directory.'/package';
        $this->app($old, '1.0.0');
        $this->app($source, '2.0.0');
        symlink($old, $this->directory.'/current');
        file_put_contents($source.'/fail-postdeploy', '1');
        file_put_contents($old.'/uploads/customer.txt', 'keep');
        self::assertFalse($this->deploy($this->service($old), $source));
        self::assertSame($old, realpath($this->directory.'/current'));
        self::assertSame('keep', file_get_contents($old.'/uploads/customer.txt'));
        self::assertFileDoesNotExist($old.'/var/update-maintenance.flag');
    }

    public function testBrowserUpdateResetsServingOpcacheWhenTimestampChecksAreDisabled(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('OPcache extension is required for this runtime integration check.');
        }
        $active = $this->directory.'/panel';
        $source = $this->directory.'/package';
        $this->app($active, '1.0.0');
        $this->app($source, '2.0.0');
        $url = $this->startServer($active.'/public', $source);
        self::assertSame('1.0.0', file_get_contents($url));
        $cache = json_decode(file_get_contents($url.'cache'), true);
        self::assertTrue($cache['opcache_enabled']);
        self::assertGreaterThan(0, $cache['opcache_statistics']['num_cached_scripts']);
        self::assertSame('activated', file_get_contents($url.'activate'));
        self::assertSame('2.0.0', file_get_contents($url));
    }
}
