<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class RegisterFlowTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testRegistrationDisabledBlocksGetAndPost(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();

        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_REGISTRATION_ENABLED => false,
        ]);

        $client->request('GET', '/register');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/register', []);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegistrationEnabledCreatesUnverifiedUser(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();

        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_REGISTRATION_ENABLED => true,
            AppSettingsService::KEY_ANTI_ABUSE_ENABLE_POW_REGISTRATION => false,
            AppSettingsService::KEY_ANTI_ABUSE_MIN_SUBMIT_SECONDS => 1,
        ]);

        $client->request('GET', '/register');
        sleep(1);

        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('public_register')->getValue();
        $email = 'new-user+' . bin2hex(random_bytes(4)) . '@example.test';

        $client->request('POST', '/register', [
            '_token' => $csrf,
            'email' => $email,
            'password' => 'P@ssw0rd!',
            'password_confirm' => 'P@ssw0rd!',
            'website' => '',
        ]);

        self::assertResponseIsSuccessful();

        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        self::assertNull($user->getEmailVerifiedAt());
        self::assertFalse($user->isMemberAccessEnabled());
    }

    public function testVerifyEmailActivatesUser(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSite();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $token = 'verify-token-123';
        $user = new User('verify@example.test', UserType::Customer);
        $user->setPasswordHash('x');
        $user->setEmailVerificationTokenHash(hash('sha256', $token));
        $user->setEmailVerificationExpiresAt((new \DateTimeImmutable())->modify('+1 day'));
        $em->persist($user);
        $em->flush();

        $url = sprintf('http://localhost/register/verify?token=%s&expires=%d', $token, time() + 3600);
        $signed = self::getContainer()->get(UriSigner::class)->sign($url);
        $path = parse_url($signed, PHP_URL_PATH) . '?' . parse_url($signed, PHP_URL_QUERY);

        $client->request('GET', $path);
        self::assertResponseRedirects('/login');

        $em->refresh($user);
        self::assertNotNull($user->getEmailVerifiedAt());
        self::assertTrue($user->isMemberAccessEnabled());
    }

    private function seedSite(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM user_sessions');
        $conn->executeStatement('DELETE FROM users');
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
