<?php

declare(strict_types=1);

namespace App\Controller\Agent;

use App\Entity\AgentRegistrationToken;
use App\Repository\AgentBootstrapTokenRepository;
use App\Repository\AgentRepository;
use App\Service\AuditLogger;
use App\Service\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class AgentBootstrapController
{
    private const ENCRYPTION_CONFIG_ERROR = 'Encryption key configuration is invalid: %s Set APP_ENCRYPTION_KEY_ID to match a key in APP_ENCRYPTION_KEYS (format: key_id:base64_32_byte_key). Example: APP_ENCRYPTION_KEY_ID=v1 and APP_ENCRYPTION_KEYS=v1:<base64 key>.';

    public function __construct(
        private readonly AgentBootstrapTokenRepository $bootstrapTokenRepository,
        private readonly AgentRepository $agentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenGenerator $tokenGenerator,
        private readonly AuditLogger $auditLogger,
        #[Autowire(service: 'limiter.agent_bootstrap')]
        private readonly RateLimiterFactory $bootstrapLimiter,
        #[Autowire('%app.agent_signature_skew_seconds%')]
        private readonly int $signatureSkewSeconds,
        #[Autowire('%app.windows_nodes_enabled%')]
        private readonly bool $windowsNodesEnabled,
    ) {
    }

    #[Route(path: '/api/v1/agent/bootstrap', name: 'api_agent_bootstrap', methods: ['POST'])]
    public function bootstrap(Request $request): JsonResponse
    {
        $limiter = $this->bootstrapLimiter->create($request->getClientIp() ?? 'agent-bootstrap');
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $response = new JsonResponse([
                'error' => 'Too many bootstrap attempts. Please try again later.',
            ], JsonResponse::HTTP_TOO_MANY_REQUESTS);
            if ($limit->getRetryAfter() !== null) {
                $response->headers->set('Retry-After', (string) $limit->getRetryAfter()->getTimestamp());
            }

            return $response;
        }

        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        $bootstrapToken = trim((string) ($payload['bootstrap_token'] ?? ''));
        $hostname = trim((string) ($payload['hostname'] ?? ''));
        $os = trim((string) ($payload['os'] ?? ''));
        $agentVersion = trim((string) ($payload['agent_version'] ?? ''));

        if ($bootstrapToken === '') {
            throw new BadRequestHttpException('Missing bootstrap_token.');
        }

        if ($hostname === '') {
            throw new BadRequestHttpException('Missing hostname.');
        }

        if (strcasecmp($os, 'windows') === 0 && !$this->windowsNodesEnabled) {
            throw new ServiceUnavailableHttpException(null, 'Windows nodes are currently disabled.');
        }

        $tokenHash = hash('sha256', $bootstrapToken);
        $token = $this->bootstrapTokenRepository->findActiveByHash($tokenHash);
        if ($token === null) {
            throw new UnauthorizedHttpException('bootstrap', 'Invalid or expired bootstrap token.');
        }

        $clientIp = $request->getClientIp() ?? 'unknown';
        $boundCidr = $token->getBoundCidr();
        if ($boundCidr !== null) {
            if ($clientIp === 'unknown' || !IpUtils::checkIp($clientIp, $boundCidr)) {
                throw new UnauthorizedHttpException('bootstrap', 'Bootstrap token is not valid for this IP range.');
            }
        }

        $boundNodeName = $token->getBoundNodeName();
        if ($boundNodeName !== null && strcasecmp($boundNodeName, $hostname) !== 0) {
            throw new UnauthorizedHttpException('bootstrap', 'Bootstrap token is not valid for this node name.');
        }

        $agentId = $this->generateAgentId($hostname);

        try {
            $registrationTokenData = $this->tokenGenerator->generate();
        } catch (RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(
                null,
                sprintf(self::ENCRYPTION_CONFIG_ERROR, $exception->getMessage() . '.'),
            );
        }

        $registrationToken = new AgentRegistrationToken(
            $agentId,
            $registrationTokenData['token_prefix'],
            $registrationTokenData['token_hash'],
            $registrationTokenData['encrypted_token'],
            new \DateTimeImmutable('+10 minutes'),
            $token,
        );
        $this->entityManager->persist($registrationToken);

        $token->markUsed();

        $fingerprintPayload = [
            'hostname' => $hostname,
            'os' => $os,
            'agent_version' => $agentVersion,
            'ip' => $clientIp,
        ];
        $fingerprint = hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR));

        $this->auditLogger->log(null, 'agent.bootstrap_used', [
            'bootstrap_token_id' => $token->getId(),
            'bootstrap_token_prefix' => $token->getTokenPrefix(),
            'agent_id' => $agentId,
            'node_fingerprint' => $fingerprint,
            'hostname' => $hostname,
            'os' => $os,
            'agent_version' => $agentVersion,
            'ip' => $clientIp,
        ]);

        $this->auditLogger->log(null, 'agent.bootstrap_registration_token_created', [
            'registration_token_prefix' => $registrationToken->getTokenPrefix(),
            'agent_id' => $agentId,
            'bootstrap_token_id' => $token->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'register_url' => $request->getSchemeAndHttpHost() . '/api/v1/agent/register',
            'register_token' => $registrationTokenData['token'],
            'core_public_url' => $request->getSchemeAndHttpHost(),
            'polling_interval' => 30,
            'time_sync_hint' => [
                'server_time' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'max_skew_seconds' => $this->signatureSkewSeconds,
            ],
            'agent_id' => $agentId,
        ]);
    }

    private function generateAgentId(string $hostname): string
    {
        $candidate = strtolower($hostname);
        $candidate = preg_replace('/[^a-z0-9-]+/', '-', $candidate);
        $candidate = trim((string) $candidate, '-');

        if ($candidate === '') {
            $candidate = 'node';
        }

        if (mb_strlen($candidate) > 52) {
            $candidate = mb_substr($candidate, 0, 52);
        }

        $suffix = bin2hex(random_bytes(4));
        $agentId = sprintf('%s-%s', $candidate, $suffix);

        while ($this->agentRepository->find($agentId) !== null) {
            $suffix = bin2hex(random_bytes(4));
            $agentId = sprintf('%s-%s', $candidate, $suffix);
        }

        return $agentId;
    }
}
