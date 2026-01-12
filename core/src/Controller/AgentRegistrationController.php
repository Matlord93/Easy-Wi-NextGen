<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Agent;
use App\Repository\AgentRepository;
use App\Repository\AgentRegistrationTokenRepository;
use App\Service\AgentSignatureVerifier;
use App\Service\AuditLogger;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AgentRegistrationController
{
    private const ENCRYPTION_CONFIG_ERROR = 'Encryption key configuration is invalid: %s Set APP_ENCRYPTION_KEY_ID to match a key in APP_ENCRYPTION_KEYS (format: key_id:base64_32_byte_key). Example: APP_ENCRYPTION_KEY_ID=v1 and APP_ENCRYPTION_KEYS=v1:<base64 key>.';

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly AgentRegistrationTokenRepository $registrationTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AgentSignatureVerifier $signatureVerifier,
        private readonly AuditLogger $auditLogger,
        #[Autowire('%env(default::AGENT_REGISTRATION_TOKEN)%')]
        private readonly string $registrationToken,
    ) {
    }

    #[Route(path: '/api/v1/agent/register', name: 'api_agent_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        $agentId = (string) ($payload['agent_id'] ?? '');
        $name = isset($payload['name']) ? trim((string) $payload['name']) : null;
        if ($name === '') {
            $name = null;
        }
        $registerToken = (string) ($payload['register_token'] ?? '');
        $registrationToken = null;
        $signatureSecret = '';

        if ($registerToken !== '') {
            $tokenHash = hash('sha256', $registerToken);
            $registrationToken = $this->registrationTokenRepository->findActiveByHash($tokenHash);
            if ($registrationToken === null) {
                throw new UnauthorizedHttpException('hmac', 'Invalid or expired registration token.');
            }

            if ($agentId === '') {
                $agentId = $registrationToken->getAgentId();
            } elseif ($agentId !== $registrationToken->getAgentId()) {
                throw new BadRequestHttpException('Agent ID does not match bootstrap registration token.');
            }

            $signatureSecret = $registerToken;
        } else {
            if ($this->registrationToken === '') {
                throw new ServiceUnavailableHttpException(null, 'Agent registration token is not configured.');
            }

            $signatureSecret = $this->registrationToken;
        }

        if ($agentId === '') {
            throw new BadRequestHttpException('Missing agent_id.');
        }

        $this->signatureVerifier->verify($request, $agentId, $signatureSecret);

        if ($this->agentRepository->find($agentId) !== null) {
            throw new ConflictHttpException('Agent already exists.');
        }

        $secret = bin2hex(random_bytes(32));

        try {
            $secretPayload = $this->encryptionService->encrypt($secret);
        } catch (RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(
                null,
                sprintf(self::ENCRYPTION_CONFIG_ERROR, $exception->getMessage() . '.'),
            );
        }

        $agent = new Agent($agentId, $secretPayload, $name);
        $this->entityManager->persist($agent);
        $this->auditLogger->log(null, 'agent.registered', [
            'agent_id' => $agentId,
            'name' => $name,
        ]);
        if ($registrationToken !== null) {
            $registrationToken->markUsed();
            $this->auditLogger->log(null, 'agent.bootstrap_registration_token_used', [
                'registration_token_prefix' => $registrationToken->getTokenPrefix(),
                'agent_id' => $agentId,
            ]);
        }
        $this->entityManager->flush();

        return new JsonResponse([
            'agent_id' => $agentId,
            'secret' => $secret,
        ], JsonResponse::HTTP_CREATED);
    }
}
