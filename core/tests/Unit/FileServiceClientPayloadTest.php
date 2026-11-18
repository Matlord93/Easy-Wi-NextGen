<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Module\Core\Application\AppSettingsServiceInterface;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Application\Exception\FileServiceException;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\InstanceFilesystemResolver;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Application\GameServerPathResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

final class FileServiceClientPayloadTest extends TestCase
{
    public function testListingPreservesStructuredRows(): void
    {
        [$client, $instance] = $this->client(['root_path' => '/srv/instances/gs1', 'path' => '', 'entries' => [['name' => 'server.cfg', 'size' => 0]], 'warnings' => []]);
        $result = $client->list($instance, '');

        self::assertSame([['name' => 'server.cfg', 'size' => 0]], $result['entries']);
        self::assertSame(1, $result['total']);
        self::assertSame([], $result['warnings']);
    }

    public function testMalformedListingEntryIsRejectedBeforeItReachesTheUi(): void
    {
        [$client, $instance] = $this->client(['entries' => ['not an object']]);
        $this->expectExceptionMessage('Invalid agent file listing entry.');
        $client->list($instance, '');
    }

    public function testMalformedNestedErrorMessageUsesSafeFallbackAndPreservesCode(): void
    {
        [$client, $instance] = $this->client(['error' => ['code' => 'path_not_found', 'message' => ['not text']]], 404);
        try {
            $client->list($instance, '');
            self::fail('The failed request must throw.');
        } catch (FileServiceException $exception) {
            self::assertSame('path_not_found', $exception->getErrorCode());
            self::assertSame('Agent file API error.', $exception->getMessage());
        }
    }

    /** @return array{FileServiceClient, Instance} */
    private function client(array $payload, int $status = 200): array
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $customer->setId(1);
        $node = new Agent('node', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $node->setServiceBaseUrl('https://agent.example.test');
        $instance = $this->createStub(Instance::class);
        $instance->method('getId')->willReturn(1);
        $instance->method('getCustomer')->willReturn($customer);
        $instance->method('getNode')->willReturn($node);
        $instance->method('getInstallPath')->willReturn('/srv/instances/gs1');
        $encryption = $this->createStub(EncryptionServiceInterface::class);
        $encryption->method('decrypt')->willReturn('secret');
        $logger = new NullLogger();
        $paths = new GameServerPathResolver(new InstanceFilesystemResolver($this->createStub(AppSettingsServiceInterface::class)), $logger);
        $http = new MockHttpClient(new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => $status]));

        return [new FileServiceClient($http, $encryption, 10, 7456, 'https', $logger, new RequestStack(), $paths), $instance];
    }
}
