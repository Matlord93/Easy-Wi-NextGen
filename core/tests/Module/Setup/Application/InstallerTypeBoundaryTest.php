<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup\Application;

use App\Module\Setup\Application\InstallerService;
use App\Module\Setup\UI\Controller\InstallController;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\AppSetting;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class InstallerTypeBoundaryTest extends TestCase
{
    public function testDatabaseConfigKeepsUrlEncodingAndTypedConnectionOptions(): void
    {
        $service = (new \ReflectionClass(InstallerService::class))->newInstanceWithoutConstructor();
        $config = $service->buildDatabaseConfig([
            'host' => '127.0.0.1', 'port' => '3307', 'name' => 'panel',
            'user' => 'panel@user', 'password' => 'p:a/ss',
        ]);

        self::assertSame('mysql://panel%40user:p%3Aa%2Fss@127.0.0.1:3307/panel?charset=utf8mb4', $config['url']);
        self::assertSame('pdo_mysql', $config['connection']['driver']);
        self::assertSame(3307, $config['connection']['port']);
        self::assertSame('p:a/ss', $config['connection']['password']);
        $withoutOptional = $service->buildDatabaseConfig([
            'host' => 'localhost', 'port' => '', 'name' => 'panel', 'user' => 'panel', 'password' => '',
        ]);
        self::assertArrayNotHasKey('port', $withoutOptional['connection']);
        self::assertArrayNotHasKey('password', $withoutOptional['connection']);
    }

    public function testStateReadsOnlyObjectFieldsAndPreservesNestedData(): void
    {
        $directory = sys_get_temp_dir().'/installer-types-'.bin2hex(random_bytes(6));
        mkdir($directory.'/srv/setup/state', 0775, true);
        $service = (new \ReflectionClass(InstallerService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'projectDir'))->setValue($service, $directory);
        $path = $directory.'/srv/setup/state/install.state.json';

        try {
            file_put_contents($path, '{"database":{"host":"localhost","saved":true},"1":"invalid"}');
            self::assertSame(['database' => ['host' => 'localhost', 'saved' => true]], $service->readState());
            file_put_contents($path, 'null');
            self::assertSame([], $service->readState());
            file_put_contents($path, 'broken json');
            self::assertSame([], $service->readState());
        } finally {
            unlink($path);
            rmdir(dirname($path));
            rmdir($directory.'/srv/setup');
            rmdir($directory.'/srv');
            rmdir($directory);
        }
    }

    public function testSettingsPreserveMixedValuesAndClearBlankSecrets(): void
    {
        $existing = new AppSetting('arbitrary', null);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::exactly(3))->method('find')->willReturnCallback(
            static fn (string $class, string $key): ?AppSetting => $key === 'arbitrary' ? $existing : null,
        );
        $created = [];
        $manager->expects(self::exactly(2))->method('persist')->willReturnCallback(
            static function (AppSetting $setting) use (&$created): void {
                $created[$setting->getSettingKey()] = $setting->getValue();
            },
        );
        $manager->expects(self::once())->method('flush');
        $service = (new \ReflectionClass(InstallerService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'encryptionService'))->setValue($service, new EncryptionService(null, null));

        $service->storeAppSettings($manager, [
            'arbitrary' => ['nested' => [false, 0, null]],
            'sftp_password' => '   ',
            'plain_flag' => false,
        ]);

        self::assertSame(['nested' => [false, 0, null]], $existing->getValue());
        self::assertSame(['sftp_password' => null, 'plain_flag' => false], $created);
    }

    public function testMigrationErrorReportsRequiredPhpVersionFromNestedException(): void
    {
        $controller = (new \ReflectionClass(InstallController::class))->newInstanceWithoutConstructor();
        $error = new \RuntimeException('Migration failed', 0, new \RuntimeException('Dependencies require a PHP version ">= 8.4.1". You are running 8.3.9.'));
        $result = (new \ReflectionMethod($controller, 'resolveDatabaseMigrationError'))->invoke($controller, [], $error);

        self::assertSame([
            'key' => 'errors.php_version_too_low',
            'params' => ['%required%' => '8.4.1', '%current%' => '8.3.9.'],
        ], $result);
    }
}
