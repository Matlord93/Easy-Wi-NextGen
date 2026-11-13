<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\UserSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\BrowserKit\Cookie;

final class LiveConsoleHttpTest extends AbstractWebTestCase
{
    private int $instanceId;
    private int $customerId;
    private array $savedEnv = [];
    private const PROXY = 'synthetic-private-proxy-key-1234567890';
    private const AGENT = 'synthetic-private-agent-key-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['CONSOLE_PROXY_SECRET' => self::PROXY, 'CONSOLE_PUBLIC_ORIGIN' => 'https://panel.example.test', 'APP_ENCRYPTION_KEYS' => 'v1:'.base64_encode(str_repeat('t', 32)), 'ACTIVE_ENCRYPTION_KEY_ID' => 'v1'] as $key => $value) {
            $this->savedEnv[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $this->seedSite();
        self::bootKernel();
        $instance = $this->seedInstance();
        $node = $instance->getNode();
        $node->setSecretPayload(self::getContainer()->get(EncryptionService::class)->encrypt(self::AGENT));
        $node->setServiceBaseUrl('https://private-agent.example.test:7456');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->instanceId = $instance->getId();
        $this->customerId = $instance->getCustomer()->getId();
        self::ensureKernelShutdown();
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->savedEnv as $key => [$env, $server]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            } if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
        }
    }
    private function ownClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $customer = $em->find(\App\Module\Core\Domain\Entity\User::class, $this->customerId);
        $session = new UserSession($customer, hash('sha256', 'synthetic-live-session'));
        $session->setExpiresAt(new \DateTimeImmutable('+2 minutes'));
        $em->persist($session);
        $em->flush();
        $client->getCookieJar()->set(new Cookie('easywi_customer_session', 'synthetic-live-session'));
        return $client;
    }
    private function headers(): array
    {
        return ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_EASYWI_CONSOLE_PROXY' => self::PROXY, 'HTTP_X_EASYWI_CONSOLE_ORIGIN' => 'https://panel.example.test'];
    }
    private function url(): string
    {
        return '/api/v1/customer/instances/'.$this->instanceId.'/console/live-authorize';
    }
    public function testOnlyPrivateProxyGetsInstanceBoundShortLivedGrant(): void
    {
        $client = $this->ownClient();
        $client->request('GET', $this->url(), server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($client->getResponse()->headers->has('X-Console-Authorization'));
        $client->request('GET', $this->url(), server: $this->headers());
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $client->getResponse()->getContent());
        self::assertSame('https://private-agent.example.test:7456/v1/console/live/'.$this->instanceId, $client->getResponse()->headers->get('X-Console-Target'));
        $token = substr($client->getResponse()->headers->get('X-Console-Authorization'), 7);
        [$payload, $signature] = explode('.', $token);
        $decode = static fn (string $data): string => base64_decode(strtr($data, '-_', '+/'));
        self::assertSame(hash_hmac('sha256', 'easywi-console-live-v1.'.$payload, self::AGENT, true), $decode($signature));
        $claims = json_decode($decode($payload), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame((string) $this->instanceId, $claims['instance']);
        self::assertSame('smoke-node-01', $claims['agent']);
        self::assertSame('gameserver.console.read', $claims['scope']);
        self::assertSame('https://panel.example.test', $claims['origin']);
        self::assertLessThanOrEqual(time() + 30, $claims['exp']);
        self::assertLessThanOrEqual(time() + 120, $claims['until']);
        self::assertSame(32, strlen($claims['nonce']));
    }
    public function testForeignCustomerAndCrossOriginCannotReadConsole(): void
    {
        $client = $this->ownClient();
        $headers = $this->headers();
        $headers['HTTP_X_EASYWI_CONSOLE_ORIGIN'] = 'https://evil.example.test';
        $client->request('GET', $this->url(), server: $headers);
        self::assertResponseStatusCodeSame(403);
        $client->getCookieJar()->clear();
        $this->loginAsRole($client, 'customer');
        $client->request('GET', $this->url(), server: $this->headers());
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($client->getResponse()->headers->has('X-Console-Authorization'));
    }
    public function testRevokedLoginCannotObtainGrant(): void
    {
        $client = $this->ownClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(UserSession::class)->findAll() as $session) {
            $session->revoke();
        } $em->flush();
        $client->request('GET', $this->url(), server: $this->headers());
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($client->getResponse()->headers->has('X-Console-Authorization'));
    }
}
