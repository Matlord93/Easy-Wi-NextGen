<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Domain\Entity\AgentBootstrapToken;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\AgentBootstrapTokenRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\TokenGenerator;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/agent-bootstrap-tokens')]
final class AdminAgentBootstrapTokenController
{
    private const ENCRYPTION_CONFIG_ERROR = 'Encryption key configuration is invalid: %s Set APP_ENCRYPTION_KEY_ID to match a key in APP_ENCRYPTION_KEYS (format: key_id:base64_32_byte_key). Example: APP_ENCRYPTION_KEY_ID=v1 and APP_ENCRYPTION_KEYS=v1:<base64 key>.';
    private const RECENT_TOKEN_LIMIT = 50;

    public function __construct(
        private readonly AgentBootstrapTokenRepository $bootstrapTokenRepository,
        private readonly TokenGenerator $tokenGenerator,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_agent_bootstrap_tokens', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return $this->renderIndex();
    }

    #[Route(path: '', name: 'admin_agent_bootstrap_tokens_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $name = trim((string) $request->request->get('name', ''));
        $expiresIn = (int) $request->request->get('expires_in', 15);
        $boundCidr = trim((string) $request->request->get('bound_cidr', ''));
        $boundNodeName = trim((string) $request->request->get('bound_node_name', ''));

        if ($name === '') {
            $name = sprintf('Bootstrap token %s', (new \DateTimeImmutable())->format('Y-m-d H:i'));
        }

        $errors = [];

        if ($expiresIn <= 0 || $expiresIn > 1440) {
            $errors[] = 'Expiry must be between 1 and 1440 minutes.';
            $expiresIn = 15;
        }

        if ($boundCidr !== '' && !$this->isValidCidr($boundCidr)) {
            $errors[] = 'Bound CIDR is not a valid IPv4/IPv6 range.';
        }

        if (mb_strlen($name) > 190) {
            $errors[] = 'Name must be 190 characters or less.';
        }

        if (mb_strlen($boundNodeName) > 190) {
            $errors[] = 'Bound node name must be 190 characters or less.';
        }

        if ($errors !== []) {
            return $this->renderIndex($errors, [
                'name' => $name,
                'expires_in' => $expiresIn,
                'bound_cidr' => $boundCidr,
                'bound_node_name' => $boundNodeName,
            ]);
        }

        try {
            $tokenData = $this->tokenGenerator->generate();
        } catch (RuntimeException $exception) {
            throw new ServiceUnavailableHttpException(
                null,
                sprintf(self::ENCRYPTION_CONFIG_ERROR, $exception->getMessage() . '.'),
            );
        }

        $token = new AgentBootstrapToken(
            $name,
            $tokenData['token_prefix'],
            $tokenData['token_hash'],
            $tokenData['encrypted_token'],
            new \DateTimeImmutable(sprintf('+%d minutes', $expiresIn)),
            $actor,
        );
        $token->setBoundCidr($boundCidr !== '' ? $boundCidr : null);
        $token->setBoundNodeName($boundNodeName !== '' ? $boundNodeName : null);

        $this->entityManager->persist($token);
        $this->auditLogger->log($actor, 'agent.bootstrap_token_created', [
            'bootstrap_token_prefix' => $token->getTokenPrefix(),
            'name' => $token->getName(),
            'expires_at' => $token->getExpiresAt()->format(DATE_ATOM),
            'bound_cidr' => $token->getBoundCidr(),
            'bound_node_name' => $token->getBoundNodeName(),
        ]);
        $this->entityManager->flush();

        return $this->renderIndex([], [
            'name' => '',
            'expires_in' => 15,
            'bound_cidr' => '',
            'bound_node_name' => '',
        ], $tokenData['token']);
    }

    #[Route(path: '/{id}/revoke', name: 'admin_agent_bootstrap_tokens_revoke', methods: ['POST'])]
    public function revoke(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $token = $this->bootstrapTokenRepository->find($id);
        if (!$token instanceof AgentBootstrapToken) {
            throw new NotFoundHttpException('Bootstrap token not found.');
        }

        $token->revoke();
        $this->auditLogger->log($actor, 'agent.bootstrap_token_revoked', [
            'bootstrap_token_prefix' => $token->getTokenPrefix(),
            'name' => $token->getName(),
        ]);
        $this->entityManager->flush();

        return $this->renderIndex();
    }

    private function renderIndex(array $errors = [], array $form = [], ?string $createdToken = null): Response
    {
        $tokens = $this->bootstrapTokenRepository->findRecent(self::RECENT_TOKEN_LIMIT);

        return new Response($this->twig->render('admin/agent-bootstrap-tokens/index.html.twig', [
            'activeNav' => 'bootstrap-tokens',
            'tokens' => $this->normalizeTokens($tokens),
            'errors' => $errors,
            'form' => array_merge([
                'name' => '',
                'expires_in' => 15,
                'bound_cidr' => '',
                'bound_node_name' => '',
            ], $form),
            'createdToken' => $createdToken,
            'recentTokenLimit' => self::RECENT_TOKEN_LIMIT,
        ]));
    }

    /**
     * @param AgentBootstrapToken[] $tokens
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTokens(array $tokens): array
    {
        $now = new \DateTimeImmutable();

        return array_map(function (AgentBootstrapToken $token) use ($now): array {
            $status = 'active';
            if ($token->isRevoked()) {
                $status = 'revoked';
            } elseif ($token->isUsed()) {
                $status = 'used';
            } elseif ($token->isExpired($now)) {
                $status = 'expired';
            }

            return [
                'id' => $token->getId(),
                'name' => $token->getName(),
                'prefix' => $token->getTokenPrefix(),
                'boundCidr' => $token->getBoundCidr(),
                'boundNodeName' => $token->getBoundNodeName(),
                'expiresAt' => $token->getExpiresAt(),
                'usedAt' => $token->getUsedAt(),
                'revokedAt' => $token->getRevokedAt(),
                'status' => $status,
            ];
        }, $tokens);
    }

    private function isValidCidr(string $cidr): bool
    {
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}(?:\/\d{1,2})?$/', $cidr) === 1) {
            return true;
        }

        return preg_match('/^[0-9a-fA-F:]+(?:\/\d{1,3})?$/', $cidr) === 1;
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
