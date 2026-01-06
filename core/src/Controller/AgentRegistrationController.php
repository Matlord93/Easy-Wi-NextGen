<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Agent;
use App\Repository\AgentRepository;
use App\Service\AgentSignatureVerifier;
use App\Service\AuditLogger;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AgentRegistrationController
{
    public function __construct(
        private readonly AgentRepository $agentRepository,
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
        if ($this->registrationToken === '') {
            throw new ServiceUnavailableHttpException(null, 'Agent registration token is not configured.');
        }

        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        $agentId = (string) ($payload['agent_id'] ?? '');
        $name = isset($payload['name']) ? (string) $payload['name'] : null;

        if ($agentId === '') {
            throw new BadRequestHttpException('Missing agent_id.');
        }

        $this->signatureVerifier->verify($request, $agentId, $this->registrationToken);

        if ($this->agentRepository->find($agentId) !== null) {
            throw new ConflictHttpException('Agent already exists.');
        }

        $secret = bin2hex(random_bytes(32));
        $secretPayload = $this->encryptionService->encrypt($secret);

        $agent = new Agent($agentId, $secretPayload, $name);
        $this->entityManager->persist($agent);
        $this->auditLogger->log(null, 'agent.registered', [
            'agent_id' => $agentId,
            'name' => $name,
        ]);
        $this->entityManager->flush();

        return new JsonResponse([
            'agent_id' => $agentId,
            'secret' => $secret,
        ], JsonResponse::HTTP_CREATED);
    }
}
