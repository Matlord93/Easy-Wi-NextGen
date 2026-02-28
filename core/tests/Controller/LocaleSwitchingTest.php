<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Domain\Entity\InvoicePreferences;
use App\Module\Core\Domain\Entity\UserSession;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InvoicePreferencesRepository;
use App\Security\SessionTokenGenerator;
use Doctrine\ORM\EntityManagerInterface;

final class LocaleSwitchingTest extends AbstractWebTestCase
{
    public function testAnonymousSwitchingIsImmediateAndPersistent(): void
    {
        $this->seedSite();

        self::ensureKernelShutdown();
        $client = static::createClient(server: ['HTTPS' => 'on']);

        $client->request('GET', '/install?lang=en');

        self::assertStringContainsString('<html lang="en"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('portal_language=en', implode(';', $client->getResponse()->headers->all('set-cookie')));

        $client->request('GET', '/install');

        self::assertStringContainsString('<html lang="en"', (string) $client->getResponse()->getContent());
    }

    public function testAuthenticatedSwitchingPersistsInDatabase(): void
    {
        $this->seedSite();

        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tokenGenerator = self::getContainer()->get(SessionTokenGenerator::class);

        $user = $this->createUser('locale-switch@example.test', UserType::Customer);
        $user->setMemberAccessEnabled(true);
        $user->setCustomerAccessEnabled(true);

        $preferences = new InvoicePreferences($user, 'de_DE', true, true, 'manual', 'de');
        $em->persist($preferences);

        $rawToken = $tokenGenerator->generateToken();
        $session = new UserSession($user, $tokenGenerator->hashToken($rawToken));
        $session->setExpiresAt((new \DateTimeImmutable())->modify('+1 day'));
        $em->persist($session);
        $em->flush();

        self::ensureKernelShutdown();
        $client = static::createClient(server: ['HTTPS' => 'on']);
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('easywi_session', $rawToken));

        $client->request('GET', '/customer/profile?lang=en');
        self::assertResponseIsSuccessful();

        self::bootKernel();
        /** @var InvoicePreferencesRepository $repository */
        $repository = self::getContainer()->get(InvoicePreferencesRepository::class);
        $updated = $repository->findOneByCustomer($user);

        self::assertNotNull($updated);
        self::assertSame('en', $updated->getPortalLanguage());

        $client->request('GET', '/customer/profile');
        self::assertStringContainsString('lang="en"', (string) $client->getResponse()->getContent());
    }

    public function testInvalidLocaleInjectionIsIgnored(): void
    {
        $this->seedSite();

        self::ensureKernelShutdown();
        $client = static::createClient(server: ['HTTPS' => 'on']);

        $client->request('GET', '/install?lang=de');
        $client->request('GET', '/install?lang=../../etc/passwd');

        self::assertStringContainsString('<html lang="de"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('portal_language=de', implode(';', $client->getResponse()->headers->all('set-cookie')));
    }
}
