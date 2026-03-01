<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\MailPolicy;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Dto\Mail\MailPolicyUpsertDto;
use App\Repository\DomainRepository;
use App\Repository\MailPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/api/v1/admin/mail/policies')]
final class AdminMailPolicyController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DomainRepository $domainRepository,
        private readonly MailPolicyRepository $mailPolicyRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/domains/{id}', methods: ['GET'])]
    public function getPolicy(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $domain = $this->domainRepository->find($id);
        if ($domain === null) {
            return new JsonResponse(['error' => 'Domain not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $policy = $this->mailPolicyRepository->findOneByDomain($domain);

        return new JsonResponse([
            'domain_id' => $domain->getId(),
            'domain' => $domain->getName(),
            'policy' => $policy?->toArray(),
        ]);
    }

    #[Route(path: '/domains/{id}', methods: ['PUT'])]
    public function upsertPolicy(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new JsonResponse(['error' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $domain = $this->domainRepository->find($id);
        if ($domain === null) {
            return new JsonResponse(['error' => 'Domain not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $dto = MailPolicyUpsertDto::fromPayload($request->toArray());
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        $policy = $this->mailPolicyRepository->findOneByDomain($domain);
        if ($policy === null) {
            $policy = new MailPolicy($domain);
            $this->entityManager->persist($policy);
        }

        $policy->apply(
            $dto->requireTls,
            $dto->maxRecipients,
            $dto->maxHourlyEmails,
            $dto->allowExternalForwarding,
            $dto->spamProtectionLevel,
            $dto->greylistingEnabled,
        );

        $this->auditLogger->log($actor, 'mail.policy_updated', [
            'domain_id' => $domain->getId(),
            'domain' => $domain->getName(),
            'policy' => $policy->toArray(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse(['domain_id' => $domain->getId(), 'policy' => $policy->toArray()]);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
