<?php

declare(strict_types=1);

namespace App\Tests\Module\Unifi;

use App\Module\Core\Application\EncryptionService;
use App\Module\Ports\Domain\Entity\PortAllocation;
use App\Module\Unifi\Application\UnifiApiClient;
use App\Module\Unifi\Application\UnifiPortSyncService;
use App\Module\Unifi\Application\UnifiRuleDiff;
use App\Module\Unifi\Application\UnifiSettingsProviderInterface;
use App\Module\Unifi\Domain\Entity\UnifiManualRule;
use App\Module\Unifi\Domain\Entity\UnifiPolicy;
use App\Module\Unifi\Domain\Entity\UnifiPortMapping;
use App\Module\Unifi\Domain\Entity\UnifiSettings;
use App\Module\Core\Domain\Entity\Instance;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UnifiSyncIsolationTest extends KernelTestCase
{
    public function testInstancePreviewCannotDeleteAnotherInstancesRules(): void
    {
        $previous = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::ensureKernelShutdown();
        try {
            self::bootKernel();
            $em = self::getContainer()->get(EntityManagerInterface::class);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $crypto = new EncryptionService('test', 'test:'.base64_encode(random_bytes(32)));
            $settings = new UnifiSettings();
            $settings->setEnabled(true);
            $settings->setBaseUrl('https://unifi.example.test');
            $settings->setUsername('test');
            $settings->setPasswordEncrypted($crypto->encrypt('synthetic-password'));
            $provider = $this->createStub(UnifiSettingsProviderInterface::class);
            $provider->method('getSettings')->willReturn($settings);
            $http = new MockHttpClient(static function (string $method, string $url): MockResponse {
                if (str_ends_with($url, '/api/auth/login')) {
                    return new MockResponse('{}', ['response_headers' => ['set-cookie: TOKEN=synthetic']]);
                }
                return new MockResponse(json_encode(['data' => [
                    ['_id' => 'one', 'name' => 'PANEL-42-tcp-1000'],
                    ['_id' => 'other', 'name' => 'PANEL-142-tcp-2000'],
                    ['_id' => 'manual', 'name' => 'MANUAL-1-tcp-8080'],
                    ['_id' => 'foreign', 'name' => 'user-managed'],
                ]], JSON_THROW_ON_ERROR));
            });
            $service = new UnifiPortSyncService($provider, $em->getRepository(UnifiPolicy::class), $em->getRepository(UnifiManualRule::class), $em->getRepository(UnifiPortMapping::class), $em->getRepository(PortAllocation::class), $em->getRepository(Instance::class), $crypto, new UnifiApiClient($http), new UnifiRuleDiff(), $em);
            $target = $service->preview(42);
            self::assertSame(['PANEL-42-tcp-1000', 'MANUAL-1-tcp-8080'], array_column($target['diff']['delete'], 'name'));
            $global = $service->preview();
            self::assertSame(['PANEL-42-tcp-1000', 'PANEL-142-tcp-2000', 'MANUAL-1-tcp-8080'], array_column($global['diff']['delete'], 'name'));
        } finally {
            self::ensureKernelShutdown();
            foreach (['SERVER', 'ENV'] as $index => $global) {
                if ($previous[$index] === null) { unset($GLOBALS['_'.$global]['DATABASE_URL']); }
                else { $GLOBALS['_'.$global]['DATABASE_URL'] = $previous[$index]; }
            }
        }
    }
}
