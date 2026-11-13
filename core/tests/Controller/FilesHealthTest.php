<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\Application\SftpFilesystemService;
use Doctrine\ORM\EntityManagerInterface;

final class FilesHealthTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        self::bootKernel();
        self::getContainer()->get('cache.rate_limiter')->clear();
        self::ensureKernelShutdown();
    }

    public function testAnonymousRequestsCannotInspectConfiguration(): void
    {
        $client = static::createClient();
        $client->request('GET', '/files/health', [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
        self::assertStringNotContainsString('sftp_host', (string) $client->getResponse()->getContent());
    }

    public function testForeignAndUnknownWebspacesAreRejectedBeforeSftpAccess(): void
    {
        [$client, $ownId, $foreignId] = $this->customerWithWebspaces();
        $service = $this->createMock(SftpFilesystemService::class);
        $service->expects(self::never())->method('list');
        $client->disableReboot();
        self::getContainer()->set(SftpFilesystemService::class, $service);
        foreach ([$foreignId, 99999999] as $id) {
            $client->request('GET', '/files/health?webspace='.$id, [], [], ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testOwnedWebspaceFailureDoesNotExposePathsOrExceptions(): void
    {
        [$client, $id] = $this->customerWithWebspaces();
        $service = $this->createMock(SftpFilesystemService::class);
        $service->expects(self::once())->method('list')->willThrowException(new \RuntimeException('Connection failed: internal-host /srv/private/docroot secret-value'));
        $client->disableReboot();
        self::getContainer()->set(SftpFilesystemService::class, $service);
        $client->request('GET', '/files/health?webspace='.$id, [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(503);
        $body = (string) $client->getResponse()->getContent();
        foreach (['internal-host', '/srv/', 'secret-value', 'root_resolution', 'entries'] as $private) {
            self::assertStringNotContainsString($private, $body);
        }
        self::assertFalse(json_decode($body, true)['root_readable']);
    }

    public function testOwnedWebspaceSuccessDoesNotReturnDirectoryEntries(): void
    {
        [$client, $id] = $this->customerWithWebspaces();
        $service = $this->createMock(SftpFilesystemService::class);
        $service->expects(self::once())->method('list')->willReturn([['name' => 'secret.env', 'path' => '/srv/private/secret.env', 'type' => 'file', 'size' => 100, 'last_modified' => 0]]);
        $client->disableReboot();
        self::getContainer()->set(SftpFilesystemService::class, $service);
        $client->request('GET', '/files/health?webspace='.$id, [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'root_readable' => true], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    private function customerWithWebspaces(): array
    {
        self::bootKernel();
        $instance = $this->seedInstance();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $other = new User('foreign-webspace@example.test', UserType::Customer);
        $other->setPasswordHash('unused');
        $em->persist($other);
        $own = new Webspace($instance->getCustomer(), $instance->getNode(), '/srv/private/own', 'public', 'own.example.test', '8.5', 100);
        $foreign = new Webspace($other, $instance->getNode(), '/srv/private/foreign', 'public', 'foreign.example.test', '8.5', 100);
        $em->persist($own);
        $em->persist($foreign);
        $em->flush();
        self::getContainer()->get(AppSettingsService::class)->updateSettings([AppSettingsService::KEY_SFTP_HOST => 'sftp.example.test']);
        $ids = [$own->getId(), $foreign->getId()];
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/');
        $client->request('POST', '/login', ['email' => 'instance-customer@example.test', 'password' => 'P@ssw0rd!']);

        return [$client, ...$ids];
    }
}
