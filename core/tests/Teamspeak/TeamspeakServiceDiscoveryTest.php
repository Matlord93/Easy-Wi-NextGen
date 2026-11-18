<?php

declare(strict_types=1);

namespace App\Tests\Teamspeak;

use App\Module\Voice\Application\Update\TeamspeakUpdateLockManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class TeamspeakServiceDiscoveryTest extends TestCase
{
    #[DataProvider('installationStates')]
    public function testVoiceLockWorksWithTheProductionDiscoveryRules(bool $legacyFilesPresent): void
    {
        $directory = sys_get_temp_dir().'/easywi-voice-container-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir([$directory.'/config', $directory.'/src/Module/Voice/Application/Update']);

        try {
            $core = dirname(__DIR__, 2);
            $services = Yaml::parseFile($core.'/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];
            $filesystem->copy($core.'/src/Module/Voice/Application/Update/TeamspeakUpdateLockManager.php', $directory.'/src/Module/Voice/Application/Update/TeamspeakUpdateLockManager.php');
            $legacyClass = 'App\\Module\\Teamspeak\\Application\\Update\\TeamspeakUpdateLockManager';

            if ($legacyFilesPresent) {
                $legacyFile = $directory.'/src/Module/Teamspeak/Application/Update/TeamspeakUpdateLockManager.php';
                $filesystem->mkdir(dirname($legacyFile));
                file_put_contents($legacyFile, <<<'PHP'
<?php
namespace App\Module\Teamspeak\Application\Update;
final class TeamspeakUpdateLockManager
{
    public function __construct(private readonly string $lockDir) {}
}
PHP);
                require_once $legacyFile;
            }

            // Public services expose the same unresolved legacy constructor as a
            // retained old controller would. Discovery and the Voice binding come
            // directly from production configuration, without hard-coded excludes.
            file_put_contents($directory.'/config/services.yaml', Yaml::dump([
                'services' => [
                    '_defaults' => ['autowire' => true, 'autoconfigure' => true, 'public' => true],
                    'App\\' => $services['App\\'],
                    TeamspeakUpdateLockManager::class => $services[TeamspeakUpdateLockManager::class],
                ],
            ], 8));

            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $directory);
            (new YamlFileLoader($container, new FileLocator($directory.'/config')))->load('services.yaml');
            $container->compile();

            self::assertFalse($container->has($legacyClass));
            $manager = $container->get(TeamspeakUpdateLockManager::class);
            self::assertInstanceOf(TeamspeakUpdateLockManager::class, $manager);
            self::assertTrue($manager->acquire('ts6', 42));
            self::assertFileExists($directory.'/var/teamspeak-update-locks/ts6-42.lock');
            self::assertFalse($manager->acquire('ts6', 42));
            $manager->release('ts6', 42);
            self::assertFileDoesNotExist($directory.'/var/teamspeak-update-locks/ts6-42.lock');
        } finally {
            $filesystem->remove($directory);
        }
    }

    public static function installationStates(): iterable
    {
        yield 'clean release' => [false];
        yield 'release copied over legacy TeamSpeak files' => [true];
    }
}
