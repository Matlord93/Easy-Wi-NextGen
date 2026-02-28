<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\TwoFactorService;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class TwoFactorPlaceholderTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testLoginWithTwoFactorRequiresPendingFlow(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedSiteAndUser('2fa-user@example.test', true);

        $client->request('POST', '/login', [
            'email' => '2fa-user@example.test',
            'password' => 'P@ssw0rd!',
        ]);

        self::assertResponseRedirects('/2fa');
        self::assertNull($client->getCookieJar()->get('easywi_session'));
    }

    public function testTwoFactorCheckFinalizesLogin(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->seedSiteAndUser('2fa-success@example.test', true);

        $client->request('POST', '/login', ['email' => $user->getEmail(), 'password' => 'P@ssw0rd!']);
        self::assertResponseRedirects('/2fa');

        self::bootKernel();
        $secret = $user->getTotpSecret(self::getContainer()->get(SecretsCrypto::class));
        self::assertNotNull($secret);

        $code = $this->findValidTotpCode((string) $secret);
        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('public_2fa_check')->getValue();

        $client->request('POST', '/2fa_check', ['otp' => $code, '_token' => $csrf]);

        self::assertResponseRedirects('/');
        self::assertNotNull($client->getCookieJar()->get('easywi_customer_session') ?? $client->getCookieJar()->get('easywi_session'));
    }

    public function testTwoFactorInvalidCodeEventuallyLocksOut(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = $this->seedSiteAndUser('2fa-lock@example.test', true);

        $client->request('POST', '/login', ['email' => $user->getEmail(), 'password' => 'P@ssw0rd!']);
        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('public_2fa_check')->getValue();

        for ($i = 0; $i < 5; $i++) {
            $client->request('POST', '/2fa_check', ['otp' => '000000', '_token' => $csrf]);
        }

        $client->request('POST', '/2fa_check', ['otp' => '000000', '_token' => $csrf]);
        self::assertResponseStatusCodeSame(429);
    }

    private function seedSiteAndUser(string $email, bool $enableTwoFactor): User
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        $this->ensureInstallLock();
        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_SECURITY_2FA_CUSTOMER_REQUIRED => false,
        ]);

        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM user_sessions');
        $conn->executeStatement('DELETE FROM users');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'localhost');
        $user = new User($email, UserType::Customer);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'P@ssw0rd!'));

        if ($enableTwoFactor) {
            $secret = self::getContainer()->get(TwoFactorService::class)->generateSecret();
            $user->setTotpSecret($secret, self::getContainer()->get(SecretsCrypto::class));
            $user->setTotpEnabled(true);
        }

        $em->persist($site);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function findValidTotpCode(string $base32Secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32Secret = rtrim(strtoupper($base32Secret), '=');

        $bits = '';
        foreach (str_split($base32Secret) as $char) {
            $index = strpos($alphabet, $char);
            if ($index === false) {
                throw new \RuntimeException('Invalid test secret.');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                continue;
            }

            $key .= chr(bindec($chunk));
        }

        $counter = intdiv(time(), 30);
        $counterBytes = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $counterBytes, $key, true);
        $offset = ord($hash[19]) & 0xf;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
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
