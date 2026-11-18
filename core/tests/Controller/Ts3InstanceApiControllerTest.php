<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\Ts3DatabaseMode;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Voice\UI\Controller\Api\Ts3InstanceApiController;
use App\Repository\AgentRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class Ts3InstanceApiControllerTest extends TestCase
{
    public static function invalidPorts(): iterable
    {
        foreach (['voice_port', 'query_port', 'file_port'] as $field) {
            foreach ([0, 65536, [], 'not-a-port'] as $value) {
                yield $field . '-' . json_encode($value, JSON_THROW_ON_ERROR) => [$field, $value];
            }
        }
    }

    #[DataProvider('invalidPorts')]
    public function testInvalidPortsReturnBadRequestBeforePersistence(string $field, mixed $value): void
    {
        $controller = (new \ReflectionClass(Ts3InstanceApiController::class))->newInstanceWithoutConstructor();
        $payload = $this->validPayload();
        $payload[$field] = $value;
        $request = Request::create('/api/ts3/instances', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR));
        $request->attributes->set('current_user', new User('admin@example.test', UserType::Admin));

        $response = $controller->create($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString($field, (string) $response->getContent());
    }

    public function testValidMysqlPayloadPreservesEntitiesPortsAndEncryptedCredentials(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $node = new Agent('node-test', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('find')->with(42)->willReturn($customer);
        $agents = $this->createMock(AgentRepositoryInterface::class);
        $agents->expects(self::once())->method('find')->with('node-test')->willReturn($node);
        $encryption = $this->createMock(EncryptionServiceInterface::class);
        $encrypted = ['key_id' => 'key', 'nonce' => 'nonce', 'ciphertext' => 'ciphertext'];
        $encryption->expects(self::once())->method('encrypt')->with('database-secret')->willReturn($encrypted);
        $controller = (new \ReflectionClass(Ts3InstanceApiController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($controller, 'userRepository'))->setValue($controller, $users);
        (new \ReflectionProperty($controller, 'agentRepository'))->setValue($controller, $agents);
        (new \ReflectionProperty($controller, 'encryptionService'))->setValue($controller, $encryption);
        $payload = $this->validPayload() + [
            'db_host' => 'mysql.example.test', 'db_port' => '3306', 'db_name' => 'teamspeak',
            'db_username' => 'teamspeak', 'db_password' => 'database-secret',
        ];
        $payload['db_mode'] = 'mysql';

        $result = (new \ReflectionMethod($controller, 'validatePayload'))->invoke($controller, $payload);

        self::assertIsArray($result);
        self::assertSame($customer, $result['customer']);
        self::assertSame($node, $result['node']);
        self::assertSame(9987, $result['voice_port']);
        self::assertSame(10011, $result['query_port']);
        self::assertSame(30033, $result['file_port']);
        self::assertSame(Ts3DatabaseMode::Mysql, $result['db_mode']);
        self::assertSame(3306, $result['db_port']);
        self::assertSame($encrypted, $result['db_password']);
    }

    public function testActionsKeepValidatedArgumentsAndRejectMissingRestorePath(): void
    {
        $controller = (new \ReflectionClass(Ts3InstanceApiController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'buildActionPayload');

        self::assertSame(['action' => 'slots', 'slots' => '24'], $method->invoke($controller, 'slots', ['slots' => '24']));
        self::assertSame(['action' => 'restore', 'restore_path' => '/srv/backup.ts3'], $method->invoke($controller, 'restore', ['restore_path' => '/srv/backup.ts3']));
        self::assertSame(['action' => 'backup'], $method->invoke($controller, 'backup', []));
        $missingPath = $method->invoke($controller, 'restore', []);
        self::assertInstanceOf(JsonResponse::class, $missingPath);
        self::assertSame(400, $missingPath->getStatusCode());
        $invalidSlots = $method->invoke($controller, 'slots', ['slots' => []]);
        self::assertInstanceOf(JsonResponse::class, $invalidSlots);
        self::assertSame(400, $invalidSlots->getStatusCode());
    }

    /** @return array<string, int|string> */
    private function validPayload(): array
    {
        return ['customer_id' => 42, 'node_id' => 'node-test', 'name' => 'TS3', 'voice_port' => '9987', 'query_port' => 10011, 'file_port' => 30033, 'db_mode' => 'sqlite'];
    }
}
