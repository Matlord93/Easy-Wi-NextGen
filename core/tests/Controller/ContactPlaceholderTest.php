<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ContactPlaceholderTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testContactNormalSubmitReturnsSuccess(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();
        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_ANTI_ABUSE_ENABLE_POW_CONTACT => false,
            AppSettingsService::KEY_ANTI_ABUSE_MIN_SUBMIT_SECONDS => 1,
        ]);

        $ip = '127.0.0.' . random_int(2, 200);
        $client->request('GET', '/contact', [], [], ['REMOTE_ADDR' => $ip]);
        sleep(1);

        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('public_contact')->getValue();
        $client->request('POST', '/contact', [
            '_token' => $csrf,
            'name' => 'Tester',
            'email' => 'tester@example.test',
            'subject' => 'Hello',
            'message' => 'Body',
            'website' => '',
        ], [], ['REMOTE_ADDR' => $ip]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Danke', (string) $client->getResponse()->getContent());
    }

    public function testContactHoneypotReturnsGenericSuccess(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();
        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_ANTI_ABUSE_ENABLE_POW_CONTACT => false,
            AppSettingsService::KEY_ANTI_ABUSE_MIN_SUBMIT_SECONDS => 1,
        ]);

        $ip = '127.0.0.' . random_int(2, 200);
        $client->request('GET', '/contact', [], [], ['REMOTE_ADDR' => $ip]);
        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('public_contact')->getValue();
        $client->request('POST', '/contact', [
            '_token' => $csrf,
            'name' => 'Spam',
            'email' => 'spam@example.test',
            'subject' => 'Spam',
            'message' => 'Spam',
            'website' => 'bot-filled',
        ], [], ['REMOTE_ADDR' => $ip]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Danke', (string) $client->getResponse()->getContent());
    }

    private function seedSite(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM sites');
        $site = new Site('Demo', 'localhost');
        $em->persist($site);
        $em->flush();
        $this->ensureInstallLock();
    }

    private function ensureSchema(EntityManagerInterface $em): void
    {
        if (self::$schemaBootstrapped) {
            return;
        }
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        if ($metadata !== []) {
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
        }
        self::$schemaBootstrapped = true;
    }

    private function ensureInstallLock(): void
    {
        $path = dirname(__DIR__, 2) . '/srv/setup/state/install.lock';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        if (!file_exists($path)) {
            file_put_contents($path, (new \DateTimeImmutable())->format(DATE_ATOM));
        }
    }
}
