<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Infrastructure\Config\DbConfigProvider;
use App\Infrastructure\Security\CryptoService;
use App\Infrastructure\Security\SecretKeyLoader;
use App\Module\Core\EventSubscriber\DatabaseConfigSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class DatabaseConfigSubscriberTest extends TestCase
{
    public function testRedirectsToInstallerWhenDatabaseConfigDoesNotExist(): void
    {
        $subscriber = new DatabaseConfigSubscriber($this->createConfigProvider('/tmp/easywi-missing-db-config.json'));
        $event = $this->createRequestEvent('/admin');

        $subscriber->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(302, $event->getResponse()->getStatusCode());
        self::assertSame('/install', $event->getResponse()->headers->get('Location'));
    }

    public function testInstallerPathIsExcludedFromRedirect(): void
    {
        $subscriber = new DatabaseConfigSubscriber($this->createConfigProvider('/tmp/easywi-missing-db-config.json'));
        $event = $this->createRequestEvent('/install');

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    private function createConfigProvider(string $configPath): DbConfigProvider
    {
        $secretKeyLoader = new SecretKeyLoader('/tmp/easywi-test-secret.key', '/tmp');
        $cryptoService = new CryptoService($secretKeyLoader);

        return new DbConfigProvider($cryptoService, $secretKeyLoader, $configPath, '/tmp');
    }

    private function createRequestEvent(string $path): RequestEvent
    {
        $kernel = new class () implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
            {
                throw new \LogicException('Not used in this test.');
            }
        };

        return new RequestEvent(
            $kernel,
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
