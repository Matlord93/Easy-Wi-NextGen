<?php

declare(strict_types=1);

namespace App\Tests\Regression;

use App\Extension\Twig\AdminWorkspaceTwigExtension;
use App\Extension\Twig\InfrastructureTwigExtension;
use App\Infrastructure\Http\FlashMessages;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\QrCodeService;
use App\Module\Core\Domain\Entity\Backup;
use App\Module\Core\Domain\Entity\BackupDefinition;
use App\Module\Core\Domain\Entity\BackupTarget;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupDestinationType;
use App\Module\Core\Domain\Enum\BackupStatus;
use App\Module\Core\Domain\Enum\BackupTargetType;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\UI\Controller\Customer\CustomerInstanceActionApiController;
use Endroid\QrCode\Writer\SvgWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PsalmIntegrationRegressionTest extends TestCase
{
    public function testFlashMessagesUseTheFlashAwareSessionContract(): void
    {
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        FlashMessages::add($request, 'success', 'Saved');

        self::assertSame(['Saved'], $session->getFlashBag()->get('success'));
    }

    public function testUnsupportedSessionFailsWithAnExplicitError(): void
    {
        $request = new Request();
        $request->setSession($this->createStub(SessionInterface::class));

        $this->expectException(\LogicException::class);
        FlashMessages::add($request, 'success', 'Saved');
    }

    public function testTwigFunctionsCanBeDiscoveredWithoutConstructingServices(): void
    {
        foreach ([AdminWorkspaceTwigExtension::class, InfrastructureTwigExtension::class] as $class) {
            $extension = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $functions = $extension->getFunctions();

            self::assertNotEmpty($functions);
            foreach ($functions as $function) {
                self::assertIsCallable($function->getCallable());
            }
        }
    }

    public function testPrimaryQrCodeRendererSupportsInstalledEndroidApi(): void
    {
        $method = new \ReflectionMethod(QrCodeService::class, 'buildWithEndroid');
        $svg = $method->invoke(new QrCodeService(), 'otpauth://totp/regression?secret=TEST', new SvgWriter());

        self::assertIsString($svg);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('width="240px"', $svg);
    }

    public function testBackupDownloadDecryptsStructuredCredentials(): void
    {
        if (!function_exists('sodium_crypto_aead_aes256gcm_encrypt')) {
            self::markTestSkipped('AEAD encryption requires libsodium.');
        }

        $encryption = new EncryptionService('test', 'test:'.base64_encode(random_bytes(32)));
        $customer = new User('backup@example.test', UserType::Customer);
        $target = new BackupTarget($customer, BackupDestinationType::Webdav, 'Backup', ['url' => 'https://backup.example.test'], [
            'username' => $encryption->encrypt('backup-user'),
            'password' => $encryption->encrypt('backup-password'),
            'token' => '',
        ]);
        $definition = new BackupDefinition($customer, BackupTargetType::Game, '1', 'Backup', $target);
        $backup = new Backup($definition, BackupStatus::Succeeded);
        $reflection = new \ReflectionClass(CustomerInstanceActionApiController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('encryptionService')->setValue($controller, $encryption);
        $payload = $reflection->getMethod('buildBackupDownloadPayload')->invoke($controller, $backup, '/srv/backup.tar.gz');

        self::assertSame([
            'username' => 'backup-user',
            'password' => 'backup-password',
        ], $payload['backup_target_secret']);
    }
}
