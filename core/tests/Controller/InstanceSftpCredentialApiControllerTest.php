<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Template;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\InstanceUpdatePolicy;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Gameserver\Infrastructure\Client\AgentGameServerClient;
use App\Module\PanelCustomer\UI\Controller\Api\InstanceSftpCredentialApiController;
use App\Repository\InstanceRepository;
use App\Repository\InstanceSftpCredentialRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class InstanceSftpCredentialApiControllerTest extends TestCase
{
    public function testShowForbiddenReturnsJsonWithRequestId(): void
    {
        $controller = new InstanceSftpCredentialApiController(
            $this->createMock(InstanceRepository::class),
            $this->createMock(InstanceSftpCredentialRepository::class),
            $this->createMock(JobRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(EncryptionService::class),
            $this->createMock(AgentGameServerClient::class),
            $this->createMock(AppSettingsService::class),
            $this->createMock(AuditLogger::class),
            new NullLogger(),
        );

        $request = new Request();
        $request->headers->set('X-Request-ID', 'req-test');
        $request->attributes->set('current_user', new User('reseller@example.test', UserType::Reseller));

        $response = $controller->show($request, 123);
        self::assertSame(403, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('sftp_forbidden', $payload['error_code']);
        self::assertSame('req-test', $payload['request_id']);
    }

    public function testResetCreatesJobAndReturnsQueued(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $this->setEntityId($customer, 10);
        $agent = new Agent('node-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c']);
        $template = new Template(
            'game',
            'Game',
            null,
            null,
            null,
            [],
            '',
            [],
            [],
            [],
            [],
            '',
            '',
            [],
            [],
        );
        $instance = new Instance($customer, $template, $agent, 1, 1, 1, null, InstanceStatus::Stopped, InstanceUpdatePolicy::Manual);
        $this->setEntityId($instance, 42);

        $instanceRepository = $this->createMock(InstanceRepository::class);
        $instanceRepository->method('find')->with(42)->willReturn($instance);

        $credentialRepository = $this->createMock(InstanceSftpCredentialRepository::class);
        $credentialRepository->method('findOneByInstance')->willReturn(null);

        $jobRepository = $this->createMock(JobRepository::class);
        $jobRepository->method('findLatestByTypeAndInstanceId')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $encryption = $this->createMock(EncryptionService::class);
        $encryption->expects($this->once())->method('encrypt')->willReturn([
            'key_id' => 'k',
            'nonce' => 'n',
            'ciphertext' => 'c',
        ]);

        $settings = $this->createMock(AppSettingsService::class);
        $settings->method('getSftpHost')->willReturn('sftp.example.test');
        $settings->method('getSftpPort')->willReturn(22);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects($this->once())->method('log');

        $agentClient = $this->createMock(AgentGameServerClient::class);
        $agentClient->method('resetInstanceAccess')->willReturn([
            'ok' => true,
            'data' => [
                'backend' => 'SFTPGO',
                'host' => 'sftp.example.test',
                'port' => 22,
                'root_path' => '/srv/instances/42',
            ],
        ]);

        $controller = new InstanceSftpCredentialApiController(
            $instanceRepository,
            $credentialRepository,
            $jobRepository,
            $entityManager,
            $encryption,
            $agentClient,
            $settings,
            $auditLogger,
            new NullLogger(),
        );

        $request = new Request();
        $request->headers->set('X-Request-ID', 'req-reset');
        $request->attributes->set('current_user', $customer);

        $response = $controller->reset($request, 42);
        self::assertSame(202, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('job', $payload);
        self::assertSame('queued', $payload['job']['status']);
        self::assertSame('req-reset', $payload['request_id']);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionClass($entity);
        if (!$reflection->hasProperty('id')) {
            return;
        }
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
