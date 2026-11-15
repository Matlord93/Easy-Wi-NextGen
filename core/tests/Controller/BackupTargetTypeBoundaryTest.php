<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Enum\BackupDestinationType;
use App\Module\PanelCustomer\UI\Controller\Api\BackupTargetApiController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BackupTargetTypeBoundaryTest extends TestCase
{
    /** @param array<string, mixed> $config */
    #[DataProvider('configurationCases')]
    public function testConfigValidationDistinguishesValidDataAndHttpErrors(BackupDestinationType $type, array $config, bool $valid): void
    {
        $controller = (new \ReflectionClass(BackupTargetApiController::class))->newInstanceWithoutConstructor();
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $message): string => $message);
        (new \ReflectionProperty($controller, 'translator'))->setValue($controller, $translator);
        $result = (new \ReflectionMethod($controller, 'normalizeConfig'))->invoke($controller, $type, $config);

        if (!$valid) {
            self::assertInstanceOf(JsonResponse::class, $result);
            self::assertSame(400, $result->getStatusCode());
            return;
        }
        self::assertIsArray($result);
        self::assertNull($result['error']);
        self::assertIsArray($result['config']);
        if ($type === BackupDestinationType::Webdav) {
            self::assertFalse($result['config']['verify_tls']);
        }
    }

    /** @return iterable<string, array{BackupDestinationType, array<string, mixed>, bool}> */
    public static function configurationCases(): iterable
    {
        yield 'local' => [BackupDestinationType::Local, ['path' => '/backups'], true];
        yield 's3' => [BackupDestinationType::S3, ['bucket' => 'archive-bucket'], true];
        yield 'webdav' => [BackupDestinationType::Webdav, ['url' => 'https://backup.example.test', 'verify_tls' => false], true];
        yield 'nfs' => [BackupDestinationType::Nfs, ['host' => '10.0.0.5', 'export_path' => '/exports', 'mount_path' => '/mnt/backups'], true];
        yield 'smb' => [BackupDestinationType::Smb, ['host' => 'storage', 'share' => 'backups', 'mount_path' => '/mnt/backups'], true];
        yield 'invalid local path' => [BackupDestinationType::Local, ['path' => ['invalid']], false];
        yield 'invalid s3 bucket' => [BackupDestinationType::S3, ['bucket' => ['invalid']], false];
        yield 'invalid webdav flag' => [BackupDestinationType::Webdav, ['url' => 'https://backup.example.test', 'verify_tls' => ['invalid']], false];
    }
}
