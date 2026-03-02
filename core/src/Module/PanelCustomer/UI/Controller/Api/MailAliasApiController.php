<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\MailAliasLoopGuard;
use App\Module\Core\Application\Mail\MailBackendContext;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\MailAlias;
use App\Module\Core\Domain\Entity\User;
use App\Repository\DomainRepository;
use App\Repository\MailAliasRepository;
use App\Repository\MailPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class MailAliasApiController
{
    private const MAX_DESTINATIONS = 20;

    public function __construct(
        private readonly MailAliasRepository $aliasRepository,
        private readonly DomainRepository $domainRepository,
        private readonly MailPolicyRepository $mailPolicyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly MailAliasLoopGuard $loopGuard,
        private readonly MailBackendContext $mailBackendContext,
        #[Autowire('%env(default::APP_MAIL_ALIAS_MAP_PATH)%')]
        private readonly ?string $aliasMapPath,
    ) {
    }

    #[Route(path: '/mail-aliases', name: 'mail_aliases_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/mail-aliases', name: 'mail_aliases_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $backendBlock = $this->mailBackendContext->blockedResponsePayload('alias');
        if ($backendBlock !== null) {
            return new JsonResponse($backendBlock, JsonResponse::HTTP_CONFLICT);
        }

        $aliases = $actor->isAdmin()
            ? $this->aliasRepository->findBy([], ['updatedAt' => 'DESC'])
            : $this->aliasRepository->findByCustomer($actor);

        return new JsonResponse([
            'aliases' => array_map(fn (MailAlias $alias) => $this->normalizeAlias($alias), $aliases),
        ]);
    }

    #[Route(path: '/mail-aliases', name: 'mail_aliases_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/mail-aliases', name: 'mail_aliases_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $backendBlock = $this->mailBackendContext->blockedResponsePayload('alias');
        if ($backendBlock !== null) {
            return new JsonResponse($backendBlock, JsonResponse::HTTP_CONFLICT);
        }

        $payload = $request->toArray();
        $domainId = $payload['domain_id'] ?? null;
        $localPart = strtolower(trim((string) ($payload['local_part'] ?? '')));
        $destinationsInput = trim((string) ($payload['destinations'] ?? ''));
        $enabled = filter_var($payload['enabled'] ?? true, FILTER_VALIDATE_BOOL);

        $domain = null;
        if (is_numeric($domainId)) {
            $domain = $this->domainRepository->find((int) $domainId);
        }

        if ($domain === null) {
            return new JsonResponse(['error' => 'Domain not found.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$actor->isAdmin() && $domain->getCustomer()->getId() !== $actor->getId()) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $localPartError = $this->validateLocalPart($localPart);
        if ($localPartError !== null) {
            return new JsonResponse(['error' => $localPartError], JsonResponse::HTTP_BAD_REQUEST);
        }

        $destinations = $this->parseDestinations($destinationsInput);
        if ($destinations === []) {
            return new JsonResponse(['error' => 'Forward destinations are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (count($destinations) > self::MAX_DESTINATIONS) {
            return new JsonResponse(['error' => sprintf('A maximum of %d destinations is allowed.', self::MAX_DESTINATIONS)], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$actor->isAdmin()) {
            $policyError = $this->externalForwardingPolicyError($domain, $destinations);
            if ($policyError !== null) {
                return new JsonResponse(['error' => $policyError], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $address = sprintf('%s@%s', $localPart, $domain->getName());
        if ($this->aliasRepository->findOneByAddress($address) !== null) {
            return new JsonResponse(['error' => 'Alias address already exists.'], JsonResponse::HTTP_CONFLICT);
        }

        $existingAliases = $this->aliasRepository->findByCustomer($domain->getCustomer());
        if ($this->loopGuard->wouldCreateLoop($address, $destinations, $existingAliases)) {
            return new JsonResponse(['error' => 'Alias loop detected.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $alias = new MailAlias($domain, $localPart, $destinations, $enabled);
        $this->entityManager->persist($alias);
        $this->entityManager->flush();

        $job = $this->queueAliasJob('mail.alias.create', $alias, [
            'destinations' => implode(', ', $alias->getDestinations()),
            'enabled' => $alias->isEnabled() ? 'true' : 'false',
        ]);

        $this->auditLogger->log($actor, 'mail.alias_created', [
            'alias_id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'alias' => $this->normalizeAlias($alias),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/mail-aliases/{id}', name: 'mail_aliases_update', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/mail-aliases/{id}', name: 'mail_aliases_update_v1', methods: ['PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }
        $backendBlock = $this->mailBackendContext->blockedResponsePayload('alias');
        if ($backendBlock !== null) {
            return new JsonResponse($backendBlock, JsonResponse::HTTP_CONFLICT);
        }

        $alias = $this->aliasRepository->find($id);
        if ($alias === null) {
            return new JsonResponse(['error' => 'Alias not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessAlias($actor, $alias)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $request->toArray();
        $destinationsInput = trim((string) ($payload['destinations'] ?? ''));
        $enabled = array_key_exists('enabled', $payload)
            ? filter_var($payload['enabled'], FILTER_VALIDATE_BOOL)
            : $alias->isEnabled();

        $destinations = $destinationsInput !== '' ? $this->parseDestinations($destinationsInput) : $alias->getDestinations();
        if ($destinations === []) {
            return new JsonResponse(['error' => 'Forward destinations are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (count($destinations) > self::MAX_DESTINATIONS) {
            return new JsonResponse(['error' => sprintf('A maximum of %d destinations is allowed.', self::MAX_DESTINATIONS)], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$actor->isAdmin()) {
            $policyError = $this->externalForwardingPolicyError($alias->getDomain(), $destinations);
            if ($policyError !== null) {
                return new JsonResponse(['error' => $policyError], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $existingAliases = $this->aliasRepository->findByCustomer($alias->getCustomer());
        if ($this->loopGuard->wouldCreateLoop($alias->getAddress(), $destinations, $existingAliases)) {
            return new JsonResponse(['error' => 'Alias loop detected.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $previous = [
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
        ];

        $alias->setDestinations($destinations);
        $alias->setEnabled($enabled);

        $job = $this->queueAliasJob('mail.alias.update', $alias, [
            'destinations' => implode(', ', $alias->getDestinations()),
            'enabled' => $alias->isEnabled() ? 'true' : 'false',
        ]);

        $this->auditLogger->log($actor, 'mail.alias_updated', [
            'alias_id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
            'previous' => $previous,
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'alias' => $this->normalizeAlias($alias),
        ]);
    }

    #[Route(path: '/mail-aliases/{id}', name: 'mail_aliases_delete', methods: ['DELETE'])]
    #[Route(path: '/api/v1/customer/mail-aliases/{id}', name: 'mail_aliases_delete_v1', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $alias = $this->aliasRepository->find($id);
        if ($alias === null) {
            return new JsonResponse(['error' => 'Alias not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessAlias($actor, $alias)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $job = $this->queueAliasJob('mail.alias.delete', $alias, []);

        $this->auditLogger->log($actor, 'mail.alias_deleted', [
            'alias_id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->remove($alias);
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function canAccessAlias(User $actor, MailAlias $alias): bool
    {
        return $actor->isAdmin() || $alias->getCustomer()->getId() === $actor->getId();
    }

    private function queueAliasJob(string $type, MailAlias $alias, array $extraPayload): Job
    {
        $domain = $alias->getDomain();
        $mapPath = $this->aliasMapPath ?? '';
        $payload = array_merge([
            'alias_id' => (string) ($alias->getId() ?? ''),
            'domain_id' => (string) $domain->getId(),
            'local_part' => $alias->getLocalPart(),
            'address' => $alias->getAddress(),
            'map_path' => $mapPath !== '' ? $mapPath : '/etc/postfix/virtual_aliases',
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }

    private function normalizeAlias(MailAlias $alias): array
    {
        return [
            'id' => $alias->getId(),
            'address' => $alias->getAddress(),
            'domain' => [
                'id' => $alias->getDomain()->getId(),
                'name' => $alias->getDomain()->getName(),
            ],
            'destinations' => $alias->getDestinations(),
            'enabled' => $alias->isEnabled(),
            'updated_at' => $alias->getUpdatedAt()->format(DATE_RFC3339),
        ];
    }

    /**
     * @return string[]
     */
    private function parseDestinations(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $normalized = str_replace(["
", "
", ';'], ',', $input);
        $parts = array_map('trim', explode(',', $normalized));
        $destinations = [];

        foreach ($parts as $value) {
            $candidate = strtolower($value);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $destinations[$candidate] = true;
        }

        return array_keys($destinations);
    }

    /**
     * @param string[] $destinations
     */
    private function externalForwardingPolicyError(\App\Module\Core\Domain\Entity\Domain $domain, array $destinations): ?string
    {
        $policy = $this->mailPolicyRepository->findOneByDomain($domain);
        if ($policy === null || $policy->isAllowExternalForwarding()) {
            return null;
        }

        $suffix = '@' . strtolower($domain->getName());
        foreach ($destinations as $destination) {
            if (!str_ends_with(strtolower($destination), $suffix)) {
                return 'External forwarding is disabled by mail policy for this domain.';
            }
        }

        return null;
    }

    private function validateLocalPart(string $localPart): ?string
    {
        if ($localPart === '') {
            return 'Alias name is required.';
        }
        if (!preg_match('/^[a-z0-9._+\\-]+$/i', $localPart)) {
            return 'Alias name contains invalid characters.';
        }
        if (str_contains($localPart, '@')) {
            return 'Alias name must not include @.';
        }

        return null;
    }
}
