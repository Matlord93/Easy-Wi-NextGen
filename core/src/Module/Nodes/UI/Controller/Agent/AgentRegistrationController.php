<?php

declare(strict_types=1);

namespace App\Module\Nodes\UI\Controller\Agent;

use App\Module\Core\Application\AgentConfigurationException;
use App\Module\Core\Application\AgentCreator;
use App\Module\Core\Application\AgentSignatureVerifier;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Repository\AgentRegistrationTokenRepository;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AgentRegistrationController
{
    private const ENCRYPTION_CONFIG_ERROR = 'Encryption key configuration is invalid: %s Ensure /etc/easywi/secret.key is readable and contains a base64 key (JSON with active_key_id/keys or "v1:<base64>").';

    public function __construct(
        private readonly AgentRepository $agentRepository,
        private readonly AgentRegistrationTokenRepository $registrationTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly AgentSignatureVerifier $signatureVerifier,
        private readonly AgentCreator $agentCreator,
        private readonly AuditLogger $auditLogger,
        private readonly AppSettingsService $appSettingsService,
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
        $rotateExisting = (bool) ($payload['rotate_existing'] ?? false);
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
            $signatureSecret = $this->appSettingsService->getAgentRegistrationToken();
        }

        if ($agentId === '') {
            throw new BadRequestHttpException('Missing agent_id.');
        }

        $this->signatureVerifier->verify($request, $agentId, $signatureSecret);

        $secret = bin2hex(random_bytes(32));

        try {
            $secretPayload = $this->encryptionService->encrypt($secret);
        } catch (RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(
                null,
                sprintf(self::ENCRYPTION_CONFIG_ERROR, $exception->getMessage() . '.'),
            );
        }

        $existingAgent = $this->agentRepository->find($agentId);
        if ($existingAgent !== null) {
            if (!$rotateExisting) {
                throw new ConflictHttpException('Agent already exists.');
            }
            if ($registrationToken === null) {
                throw new ConflictHttpException('Agent secret rotation requires a bootstrap registration token.');
            }

            $existingAgent->setSecretPayload($secretPayload);
            $this->auditLogger->log(null, 'agent.secret_rotated', [
                'agent_id' => $agentId,
                'name' => $existingAgent->getName(),
            ]);
            $this->consumeRegistrationToken($registrationToken, $agentId);
            $this->entityManager->flush();

            return new JsonResponse([
                'agent_id' => $agentId,
                'secret' => $secret,
                'rotated' => true,
            ]);
        }

        try {
            $agent = $this->agentCreator->create($agentId, $secretPayload, $name);
        } catch (AgentConfigurationException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
        $this->entityManager->persist($agent);
        $this->auditLogger->log(null, 'agent.registered', [
            'agent_id' => $agentId,
            'name' => $name,
        ]);
        $this->consumeRegistrationToken($registrationToken, $agentId);
        $this->entityManager->flush();

        return new JsonResponse([
            'agent_id' => $agentId,
            'secret' => $secret,
        ], JsonResponse::HTTP_CREATED);
    }

    private function consumeRegistrationToken(?\App\Module\Core\Domain\Entity\AgentRegistrationToken $registrationToken, string $agentId): void
    {
        if ($registrationToken === null) {
            return;
        }

        $registrationToken->markUsed();
        $bootstrapToken = $registrationToken->getBootstrapToken();
        if ($bootstrapToken !== null) {
            $bootstrapToken->invalidate();
            $this->auditLogger->log(null, 'agent.bootstrap_token_invalidated', [
                'bootstrap_token_prefix' => $bootstrapToken->getTokenPrefix(),
                'agent_id' => $agentId,
            ]);
        }
        $this->auditLogger->log(null, 'agent.bootstrap_registration_token_used', [
            'registration_token_prefix' => $registrationToken->getTokenPrefix(),
            'agent_id' => $agentId,
        ]);
    }

}
