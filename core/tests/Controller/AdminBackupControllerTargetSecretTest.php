<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\BackupTarget;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupDestinationType;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminBackupController;
use PHPUnit\Framework\TestCase;

final class AdminBackupControllerTargetSecretTest extends TestCase
{
    public function testNormalizeTargetMarksArrayEncryptedPasswordAsSecretSet(): void
    {
        $target = $this->createWebdavTarget([
            'password' => [
                'key_id' => 'main',
                'nonce' => base64_encode('nonce'),
                'ciphertext' => base64_encode('ciphertext'),
            ],
        ]);

        $normalized = $this->normalizeTarget($target);

        self::assertTrue($normalized['secret_set']);
    }

    public function testNormalizeTargetDoesNotMarkMalformedSecretAsSet(): void
    {
        $target = $this->createWebdavTarget([
            'password' => '',
        ]);

        $normalized = $this->normalizeTarget($target);

        self::assertFalse($normalized['secret_set']);
    }

    /** @param array<string,mixed> $encryptedCredentials */
    private function createWebdavTarget(array $encryptedCredentials): BackupTarget
    {
        return new BackupTarget(
            new User('admin@example.test', UserType::Admin),
            BackupDestinationType::Webdav,
            'WebDAV target',
            [
                'url' => 'https://cloud.example/remote.php/dav/files/user',
                'remote_path' => '/easywi/backups',
                'username' => 'user',
                'verify_tls' => true,
            ],
            $encryptedCredentials,
        );
    }

    /** @return array<string,mixed> */
    private function normalizeTarget(BackupTarget $target): array
    {
        $controller = (new \ReflectionClass(AdminBackupController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(AdminBackupController::class, 'normalizeTarget');
        $method->setAccessible(true);
        $normalized = $method->invoke($controller, $target);

        self::assertIsArray($normalized);

        return $normalized;
    }
}
