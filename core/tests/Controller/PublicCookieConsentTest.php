<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\CookieConsentService;
use App\Module\Core\Domain\Entity\CmsSiteSettings;
use App\Module\Core\Domain\Entity\ForumBoard;
use App\Module\Core\Domain\Entity\ForumCategory;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class PublicCookieConsentTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    public function testBannerAndModalRenderInForumLayout(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedForumSite();

        $client->request('GET', '/forum');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-cookie-open', $html);
        self::assertStringContainsString('id="cookie-settings-modal"', $html);
        self::assertStringContainsString('Cookie-Richtlinie', $html);
        self::assertStringContainsString('href="/cookies"', $html);
    }

    public function testLegacyCookiePolicyPathRedirectsToCmsPageSlug(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/cookie-richtlinie');

        self::assertResponseRedirects('/cookies', 301);
    }

    public function testConsentEndpointStoresFirstPartyCookieWithVersion(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('POST', '/cookie-consent', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'statistics' => true,
            'marketing' => false,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();

        $cookies = $client->getResponse()->headers->getCookies();
        self::assertNotEmpty($cookies);

        $consentCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === CookieConsentService::COOKIE_NAME) {
                $consentCookie = $cookie;
                break;
            }
        }

        self::assertNotNull($consentCookie);
        self::assertSame('lax', strtolower((string) $consentCookie->getSameSite()));
        self::assertStringContainsString('"version":' . CookieConsentService::VERSION, urldecode((string) $consentCookie->getValue()));
    }

    public function testOptionalScriptsAreOnlyRenderedAfterOptIn(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->seedForumSite();

        $client->request('GET', '/forum');
        $withoutConsent = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('data-optional-consent="statistics-enabled"', $withoutConsent);

        $consent = rawurlencode(json_encode([
            'version' => CookieConsentService::VERSION,
            'necessary' => true,
            'statistics' => true,
            'marketing' => false,
        ], JSON_THROW_ON_ERROR));
        $client->getCookieJar()->set(new Cookie(CookieConsentService::COOKIE_NAME, $consent));

        $client->request('GET', '/forum');
        $withConsent = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-optional-consent="statistics-enabled"', $withConsent);
        self::assertStringNotContainsString('data-optional-consent="marketing-enabled"', $withConsent);
    }

    private function seedForumSite(): void
    {
        self::bootKernel();
        $this->ensureInstallLock();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);

        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM forum_posts');
        $conn->executeStatement('DELETE FROM forum_threads');
        $conn->executeStatement('DELETE FROM forum_boards');
        $conn->executeStatement('DELETE FROM forum_categories');
        $conn->executeStatement('DELETE FROM cms_site_settings');
        $conn->executeStatement('DELETE FROM sites');

        $site = new Site('Demo', 'localhost');
        $settings = new CmsSiteSettings($site);
        $settings->setModuleTogglesJson(['forum' => true]);
        $category = new ForumCategory($site, 'General', 'general-cat');
        $board = new ForumBoard($site, $category, 'General Board', 'general');

        $em->persist($site);
        $em->persist($settings);
        $em->persist($category);
        $em->persist($board);
        $em->flush();
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
            file_put_contents($path, "installed\n");
        }
    }
}
