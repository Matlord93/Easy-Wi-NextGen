<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Site;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AbstractWebTestCase extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    protected function seedSite(bool $forumEnabled = true): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        $this->ensureInstallLock();

        $conn = $em->getConnection();
        $conn->executeStatement('PRAGMA foreign_keys = OFF');
        $tables = [
            'forum_post_reports',
            'forum_posts',
            'forum_threads',
            'forum_boards',
            'forum_categories',
            'cms_site_settings',
            'cms_pages',
            'instance_sftp_credentials',
            'instances',
            'port_blocks',
            'agents',
            'game_templates',
            'user_sessions',
            'users',
            'sites',
        ];

        foreach ($tables as $table) {
            $conn->executeStatement(sprintf('DELETE FROM %s', $table));
        }

        $conn->executeStatement('PRAGMA foreign_keys = ON');

        $site = new Site('Demo', 'localhost');
        $homepage = new CmsPage($site, 'Startseite', 'startseite', true);

        $settings = new CmsSiteSettings($site);
        $settings->setModuleTogglesJson([
            'blog' => true,
            'events' => true,
            'team' => true,
            'forum' => $forumEnabled,
            'media' => true,
        ]);

        $em->persist($site);
        $em->persist($homepage);
        $em->persist($settings);
        $em->flush();
        self::ensureKernelShutdown();
    }

    protected function loginAsRole(KernelBrowser $client, string $role): User
    {
        $type = match (strtolower($role)) {
            'admin' => UserType::Admin,
            'reseller' => UserType::Reseller,
            default => UserType::Customer,
        };

        $user = $this->createUser(strtolower($role) . '+forum@example.test', $type);
        if ($type === UserType::Customer) {
            $user->setMemberAccessEnabled(true);
            $user->setCustomerAccessEnabled(false);
        }

        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_SECURITY_2FA_ADMIN_REQUIRED => false,
            AppSettingsService::KEY_SECURITY_2FA_GLOBAL_REQUIRED => false,
        ]);

        $client->request('GET', '/');
        $client->request('POST', '/login', [
            'email' => $user->getEmail(),
            'password' => 'P@ssw0rd!',
        ]);

        $sessionCookie = $client->getCookieJar()->get('easywi_session')
            ?? $client->getCookieJar()->get('easywi_customer_session');
        self::assertNotNull($sessionCookie);

        return $user;
    }

    protected function seedInstance(): Instance
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $customer = new User('instance-customer@example.test', UserType::Customer);
        $customer->setCustomerAccessEnabled(true);
        $customer->setPasswordHash($hasher->hashPassword($customer, 'P@ssw0rd!'));

        $agent = new Agent('smoke-node-01', ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c']);

        $template = new Template(
            'smoke-game',
            'Smoke Game',
            null, null, null,
            [], '', [], [], [], [], '', '', [], [],
        );

        $instance = new Instance(
            $customer,
            $template,
            $agent,
            1, 512, 10,
            null,
            InstanceStatus::Stopped,
            InstanceUpdatePolicy::Manual,
        );

        $em->persist($customer);
        $em->persist($agent);
        $em->persist($template);
        $em->persist($instance);
        $em->flush();

        return $instance;
    }

    protected function assertLoggedInHeaderContains(KernelBrowser $client, string $name): void
    {
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($name, $client->getResponse()->getContent() ?? '');
    }

    protected function createUser(string $email, UserType $type): User
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User($email, $type);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPasswordHash($hasher->hashPassword($user, 'P@ssw0rd!'));

        $em->persist($user);
        $em->flush();

        return $user;
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
