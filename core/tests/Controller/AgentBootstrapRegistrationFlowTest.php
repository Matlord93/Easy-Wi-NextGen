<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AgentCreator;
use App\Module\Core\Application\AgentSignatureVerifier;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\LimiterFactoryInterface;
use App\Module\Core\Application\TokenGenerator;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\AgentBootstrapToken;
use App\Module\Core\Domain\Entity\AgentRegistrationToken;
use App\Module\Nodes\UI\Controller\Agent\AgentBootstrapController;
use App\Module\Nodes\UI\Controller\Agent\AgentRegistrationController;
use App\Repository\AgentBootstrapTokenRepository;
use App\Repository\AgentRegistrationTokenRepository;
use App\Repository\AgentRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;

final class AgentBootstrapRegistrationFlowTest extends TestCase
{
    public function testBootstrapReturns503ForWindowsWhenDisabled(): void
    {
        $bootstrapRepo = $this->createMock(AgentBootstrapTokenRepository::class);
        $bootstrapRepo->expects($this->never())->method('findActiveByHash');

        $agentRepo = $this->createMock(AgentRepository::class);
        $tokenGenerator = $this->createMock(TokenGenerator::class);
        $auditLogger = $this->createMock(AuditLogger::class);

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn(new RateLimit(1, new DateTimeImmutable(), true, 1));

        $limiterFactory = $this->createMock(LimiterFactoryInterface::class);
        $limiterFactory->method('create')->willReturn($limiter);

        $entityManager = $this->createMock(EntityManagerInterface::class);

        $bootstrapController = new AgentBootstrapController(
            $bootstrapRepo,
            $agentRepo,
            $entityManager,
            $tokenGenerator,
            $auditLogger,
            $limiterFactory,
            300,
            false,
        );

        $bootstrapRequest = Request::create(
            'http://example.com/api/v1/agent/bootstrap',
            'POST',
            [],
            [],
            [],
            [],
            json_encode([
                'bootstrap_token' => 'bootstrap-token',
                'hostname' => 'node-1',
                'os' => 'windows',
                'agent_version' => '1.0.0',
            ], JSON_THROW_ON_ERROR),
        );

        $bootstrapResponse = $bootstrapController->bootstrap($bootstrapRequest);
        $this->assertSame(503, $bootstrapResponse->getStatusCode());
        $this->assertSame(
            ['error' => 'Windows nodes are currently disabled.'],
            json_decode((string) $bootstrapResponse->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testBootstrapThenRegisterHappyPath(): void
    {
        $bootstrapTokenValue = 'bootstrap-token';
        $bootstrapTokenHash = hash('sha256', $bootstrapTokenValue);
        $bootstrapToken = new AgentBootstrapToken(
            'bootstrap',
            'boot',
            $bootstrapTokenHash,
            ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c'],
            new DateTimeImmutable('+1 hour'),
        );

        $bootstrapRepo = $this->createMock(AgentBootstrapTokenRepository::class);
        $bootstrapRepo->expects($this->once())
            ->method('findActiveByHash')
            ->with($bootstrapTokenHash)
            ->willReturn($bootstrapToken);

        $agentRepo = $this->createMock(AgentRepository::class);
        $agentRepo->method('find')->willReturn(null);

        $tokenGenerator = $this->createMock(TokenGenerator::class);
        $registerToken = 'register-token';
        $registerTokenHash = hash('sha256', $registerToken);
        $tokenGenerator->expects($this->once())
            ->method('generate')
            ->willReturn([
                'token' => $registerToken,
                'token_hash' => $registerTokenHash,
                'token_prefix' => 'reg',
                'encrypted_token' => ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c'],
            ]);

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->method('log');

        $limiter = $this->createMock(LimiterInterface::class);
        $limiter->method('consume')->willReturn(new RateLimit(1, new DateTimeImmutable(), true, 1));

        $limiterFactory = $this->createMock(LimiterFactoryInterface::class);
        $limiterFactory->method('create')->willReturn($limiter);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('persist');
        $entityManager->method('flush');

        $bootstrapController = new AgentBootstrapController(
            $bootstrapRepo,
            $agentRepo,
            $entityManager,
            $tokenGenerator,
            $auditLogger,
            $limiterFactory,
            300,
            true,
        );

        $bootstrapRequest = Request::create(
            'http://example.com/api/v1/agent/bootstrap',
            'POST',
            [],
            [],
            [],
            [],
            json_encode([
                'bootstrap_token' => $bootstrapTokenValue,
                'hostname' => 'node-1',
                'os' => 'linux',
                'agent_version' => '1.0.0',
            ], JSON_THROW_ON_ERROR),
        );

        $bootstrapResponse = $bootstrapController->bootstrap($bootstrapRequest);
        $bootstrapPayload = json_decode((string) $bootstrapResponse->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $agentId = $bootstrapPayload['agent_id'];
        $registrationToken = new AgentRegistrationToken(
            $agentId,
            'reg',
            $registerTokenHash,
            ['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c'],
            new DateTimeImmutable('+10 minutes'),
            $bootstrapToken,
        );

        $registrationRepo = $this->createMock(AgentRegistrationTokenRepository::class);
        $registrationRepo->expects($this->once())
            ->method('findActiveByHash')
            ->with($registerTokenHash)
            ->willReturn($registrationToken);

        $encryptionService = $this->createMock(EncryptionService::class);
        $encryptionService->method('encrypt')
            ->willReturn(['key_id' => 'v1', 'nonce' => 'n', 'ciphertext' => 'c']);

        $signatureVerifier = new AgentSignatureVerifier(new ArrayAdapter(), new NullLogger(), 300, 600);

        $agentCreator = $this->createMock(AgentCreator::class);
        $agentCreator->method('create')
            ->willReturnCallback(static function (string $id, array $secretPayload, ?string $name): Agent {
                return new Agent($id, $secretPayload, $name);
            });

        $appSettingsService = $this->createMock(AppSettingsService::class);

        $registrationController = new AgentRegistrationController(
            $agentRepo,
            $registrationRepo,
            $entityManager,
            $encryptionService,
            $signatureVerifier,
            $agentCreator,
            $auditLogger,
            $appSettingsService,
        );

        $registerPayload = json_encode([
            'agent_id' => $agentId,
            'register_token' => $registerToken,
            'name' => '',
        ], JSON_THROW_ON_ERROR);

        $timestamp = (new DateTimeImmutable())->format(DateTimeImmutable::RFC3339);
        $nonce = 'nonce-1';
        $signaturePayload = AgentSignatureVerifier::buildSignaturePayload(
            $agentId,
            'POST',
            '/api/v1/agent/register',
            $timestamp,
            $nonce,
            $registerPayload,
        );
        $signature = hash_hmac('sha256', $signaturePayload, $registerToken);

        $registerRequest = Request::create('/api/v1/agent/register', 'POST', [], [], [], [], $registerPayload);
        $registerRequest->headers->set('X-Agent-ID', $agentId);
        $registerRequest->headers->set('X-Timestamp', $timestamp);
        $registerRequest->headers->set('X-Nonce', $nonce);
        $registerRequest->headers->set('X-Signature', $signature);

        $registerResponse = $registrationController->register($registerRequest);
        $this->assertSame(201, $registerResponse->getStatusCode());
    }
}
