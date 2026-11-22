<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Message\SendTemplateMailMessage;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\ContactMessage;
use App\Module\Core\Domain\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ContactPlaceholderTest extends WebTestCase
{
    private static bool $schemaBootstrapped = false;

    #[TestWith(['/contact', 'Danke, wir melden uns.'])]
    #[TestWith(['/kontakt', 'Nachricht gesendet!'])]
    public function testCaptchaAndProofOfWorkProtectTheActualDelivery(string $path, string $confirmation): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $transport = new InMemoryTransport();
        self::getContainer()->set('messenger.transport.async', $transport);
        $this->seedSite();
        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_ANTI_ABUSE_ENABLE_CAPTCHA_CONTACT => true,
            AppSettingsService::KEY_ANTI_ABUSE_ENABLE_POW_CONTACT => true,
            AppSettingsService::KEY_ANTI_ABUSE_POW_DIFFICULTY => 1,
            AppSettingsService::KEY_ANTI_ABUSE_MIN_SUBMIT_SECONDS => 1,
        ]);
        $server = $this->newClientAddress();
        $page = $client->request('GET', $path.'?lang=de', server: $server);
        self::assertResponseIsSuccessful();
        $payload = $this->solveForm($page) + [
            'name' => 'Tester',
            'email' => 'tester@example.test',
            'subject' => 'Legitimate contact',
            'message' => 'My message should be preserved.',
        ];
        sleep(1);

        $invalid = $client->request('POST', $path, array_replace($payload, ['captcha_answer' => '99']), server: $server);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Die Antwort auf die Sicherheitsfrage war falsch.', $invalid->filterXPath('//*[@role="alert"]')->text());
        self::assertSame($payload['message'], $invalid->filterXPath('//textarea[@name="message"]')->text());
        self::assertCount(0, $transport->getSent());
        self::assertSame(0, $this->storedMessages());

        $payload = $this->solveForm($invalid) + $payload;
        sleep(1);
        $client->request('POST', $path, $payload, server: $server);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($confirmation, $client->getResponse()->getContent());
        $isCmsInbox = $path === '/kontakt';
        $sent = $transport->getSent();
        self::assertCount($isCmsInbox ? 0 : 1, $sent);
        if (!$isCmsInbox) {
            $mail = $sent[0]->getMessage();
            self::assertInstanceOf(SendTemplateMailMessage::class, $mail);
            self::assertSame('contact_message', $mail->getTemplateKey());
            self::assertSame($payload['message'], $mail->getContext()['message_body']);
        }
        self::assertSame($isCmsInbox ? 1 : 0, $this->storedMessages());

        $transport->reset();
        $client->request('POST', $path, $payload, server: $server);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('bereits abgesendet', $client->getResponse()->getContent());
        self::assertCount(0, $transport->getSent());
        self::assertSame($isCmsInbox ? 1 : 0, $this->storedMessages());
    }

    #[TestWith(['/contact', 'Danke, wir melden uns.'])]
    #[TestWith(['/kontakt', 'Nachricht gesendet!'])]
    public function testHoneypotDoesNotDeliverAndUsesTheIpLimit(string $path, string $confirmation): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $transport = new InMemoryTransport();
        self::getContainer()->set('messenger.transport.async', $transport);
        $this->seedSite();
        $server = $this->newClientAddress();
        $page = $client->request('GET', $path.'?lang=de', server: $server);
        $payload = [
            '_token' => $page->filterXPath('//form[.//input[@name="contact_form_nonce"]]//input[@name="_token"]')->attr('value'),
            'website' => 'bot-filled',
        ];
        $attempts = $path === '/contact' ? 6 : 3;
        for ($i = 0; $i < $attempts; ++$i) {
            $client->request('POST', $path, $payload, server: $server);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($confirmation, $client->getResponse()->getContent());
            self::assertCount(0, $transport->getSent());
        }
        $client->request('POST', $path, $payload, server: $server);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        self::assertSame(0, $this->storedMessages());
    }

    public function testCmsContactRequiresCsrfAndRejectsQuickOrUnverifiedSubmissions(): void
    {
        $client = static::createClient();
        $this->seedSite();
        self::getContainer()->get(AppSettingsService::class)->updateSettings([
            AppSettingsService::KEY_ANTI_ABUSE_ENABLE_CAPTCHA_CONTACT => true,
            AppSettingsService::KEY_ANTI_ABUSE_ENABLE_POW_CONTACT => true,
            AppSettingsService::KEY_ANTI_ABUSE_POW_DIFFICULTY => 1,
            AppSettingsService::KEY_ANTI_ABUSE_MIN_SUBMIT_SECONDS => 2,
        ]);
        $server = $this->newClientAddress();
        $client->request('POST', '/kontakt', ['_token' => 'invalid'], server: $server);
        self::assertResponseStatusCodeSame(403);

        $page = $client->request('GET', '/kontakt?lang=de', server: $server);
        $quick = $client->request('POST', '/kontakt', $this->solveForm($page), server: $server);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Bitte warte einen Moment', $quick->filterXPath('//*[@role="alert"]')->text());

        $payload = $this->solveForm($quick);
        unset($payload['pow_solution']);
        sleep(2);
        $invalid = $client->request('POST', '/kontakt', $payload, server: $server);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Die automatische Sicherheitsprüfung', $invalid->filterXPath('//*[@role="alert"]')->text());
        self::assertSame(0, $this->storedMessages());
    }

    /** @return array<string, string> */
    private function solveForm(Crawler $page): array
    {
        $form = $page->filterXPath('//form[.//input[@name="contact_form_nonce"]]');
        $question = $form->filterXPath('.//label[@for="contact-captcha"]')->text();
        self::assertSame(1, preg_match('/([1-9]) \+ ([1-9]) = \?/', $question, $parts));
        self::assertSame('1', $form->attr('data-form-pow'));
        $nonce = $form->filterXPath('.//input[@name="contact_form_nonce"]')->attr('value');
        self::assertNotNull($nonce);
        $token = $form->filterXPath('.//input[@name="_token"]')->attr('value');
        self::assertNotNull($token);
        for ($solution = 0; !str_starts_with(hash('sha256', $nonce.$solution), '0'); ++$solution) {
        }

        return [
            '_token' => $token,
            'contact_form_nonce' => $nonce,
            'captcha_answer' => (string) ((int) $parts[1] + (int) $parts[2]),
            'pow_solution' => (string) $solution,
        ];
    }

    private function storedMessages(): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(ContactMessage::class)->count([]);
    }

    /** @return array{REMOTE_ADDR: string} */
    private function newClientAddress(): array
    {
        return ['REMOTE_ADDR' => '2001:db8:'.implode(':', str_split(bin2hex(random_bytes(12)), 4))];
    }

    private function seedSite(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->ensureSchema($em);
        $conn = $em->getConnection();
        $conn->executeStatement('DELETE FROM contact_messages');
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
