<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DnsRecord;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\DnsRecordRepository;
use App\Repository\DomainRepository;
use App\Service\AuditLogger;
use App\Service\DnsRecordHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class DnsRecordApiController
{
    public function __construct(
        private readonly DnsRecordRepository $dnsRecordRepository,
        private readonly DomainRepository $domainRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly DnsRecordHelper $recordHelper,
    ) {
    }

    #[Route(path: '/api/dns/records', name: 'dns_record_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/dns/records', name: 'dns_record_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        $payload = $this->parseJsonPayload($request);

        $formData = $this->validatePayload($payload);
        if ($formData['error'] !== null) {
            return $formData['error'];
        }

        $domain = $formData['domain'];
        if (!$this->canAccessDomain($actor, $domain)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $record = new DnsRecord(
            $domain,
            $formData['name'],
            $formData['type'],
            $formData['content'],
            $formData['ttl'],
            $formData['priority'],
        );

        $this->entityManager->persist($record);
        $this->entityManager->flush();
        $job = $this->queueDnsJob('dns.record.create', $record);

        $this->auditLogger->log($actor, 'dns.record_created', [
            'record_id' => $record->getId(),
            'domain_id' => $domain->getId(),
            'name' => $record->getName(),
            'type' => $record->getType(),
            'content' => $record->getContent(),
            'ttl' => $record->getTtl(),
            'priority' => $record->getPriority(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $record->getId(),
            'domain_id' => $domain->getId(),
            'name' => $record->getName(),
            'type' => $record->getType(),
            'content' => $record->getContent(),
            'ttl' => $record->getTtl(),
            'priority' => $record->getPriority(),
            'job_id' => $job->getId(),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/dns/records/{id}', name: 'dns_record_update', methods: ['PUT'])]
    #[Route(path: '/api/v1/customer/dns/records/{id}', name: 'dns_record_update_v1', methods: ['PUT'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $record = $this->dnsRecordRepository->find($id);
        if ($record === null) {
            return new JsonResponse(['error' => 'Record not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessDomain($actor, $record->getDomain())) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $formData = $this->validatePayload($payload);
        if ($formData['error'] !== null) {
            return $formData['error'];
        }

        $domain = $formData['domain'];
        if (!$this->canAccessDomain($actor, $domain)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $previous = [
            'domain_id' => $record->getDomain()->getId(),
            'name' => $record->getName(),
            'type' => $record->getType(),
            'content' => $record->getContent(),
            'ttl' => $record->getTtl(),
            'priority' => $record->getPriority(),
        ];

        $record->setDomain($domain);
        $record->setName($formData['name']);
        $record->setType($formData['type']);
        $record->setContent($formData['content']);
        $record->setTtl($formData['ttl']);
        $record->setPriority($formData['priority']);

        $job = $this->queueDnsJob('dns.record.update', $record);

        $this->auditLogger->log($actor, 'dns.record_updated', [
            'record_id' => $record->getId(),
            'domain_id' => $domain->getId(),
            'name' => $record->getName(),
            'type' => $record->getType(),
            'content' => $record->getContent(),
            'ttl' => $record->getTtl(),
            'priority' => $record->getPriority(),
            'previous' => $previous,
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $record->getId(),
            'domain_id' => $domain->getId(),
            'name' => $record->getName(),
            'type' => $record->getType(),
            'content' => $record->getContent(),
            'ttl' => $record->getTtl(),
            'priority' => $record->getPriority(),
            'job_id' => $job->getId(),
        ]);
    }

    #[Route(path: '/api/dns/records/{id}', name: 'dns_record_delete', methods: ['DELETE'])]
    #[Route(path: '/api/v1/customer/dns/records/{id}', name: 'dns_record_delete_v1', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $record = $this->dnsRecordRepository->find($id);
        if ($record === null) {
            return new JsonResponse(['error' => 'Record not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessDomain($actor, $record->getDomain())) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $job = $this->queueDnsJob('dns.record.delete', $record);

        $this->auditLogger->log($actor, 'dns.record_deleted', [
            'record_id' => $record->getId(),
            'domain_id' => $record->getDomain()->getId(),
            'name' => $record->getName(),
            'type' => $record->getType(),
            'content' => $record->getContent(),
            'ttl' => $record->getTtl(),
            'priority' => $record->getPriority(),
            'job_id' => $job->getId(),
        ]);

        $this->entityManager->remove($record);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'deleted']);
    }

    private function requireUser(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function parseJsonPayload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (\JsonException $exception) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid JSON payload.', $exception);
        }

        return is_array($payload) ? $payload : [];
    }

    private function validatePayload(array $payload): array
    {
        $domainId = $payload['domain_id'] ?? null;
        $name = $this->recordHelper->normalizeName((string) ($payload['name'] ?? ''));
        $type = strtoupper(trim((string) ($payload['type'] ?? '')));
        $content = $this->recordHelper->normalizeContent((string) ($payload['content'] ?? ''), $type);
        $ttlValue = $payload['ttl'] ?? null;
        $priorityValue = $payload['priority'] ?? null;

        $ttl = null;
        if ($ttlValue !== null && $ttlValue !== '') {
            $ttl = is_numeric($ttlValue) ? (int) $ttlValue : null;
        }

        $priority = null;
        if ($priorityValue !== null && $priorityValue !== '') {
            $priority = is_numeric($priorityValue) ? (int) $priorityValue : null;
        }

        $errors = $this->recordHelper->validate($name, $type, $content, $ttl, $priority);
        if ($errors !== []) {
            return ['error' => new JsonResponse(['errors' => $errors], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if (!is_numeric($domainId)) {
            return ['error' => new JsonResponse(['error' => 'Domain is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $domain = $this->domainRepository->find((int) $domainId);
        if ($domain === null) {
            return ['error' => new JsonResponse(['error' => 'Domain not found.'], JsonResponse::HTTP_NOT_FOUND)];
        }

        return [
            'error' => null,
            'domain' => $domain,
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'ttl' => $ttl ?? 0,
            'priority' => $priority,
        ];
    }

    private function canAccessDomain(User $actor, \App\Entity\Domain $domain): bool
    {
        if ($actor->getType() === UserType::Admin) {
            return true;
        }

        return $domain->getCustomer()->getId() === $actor->getId();
    }

    private function queueDnsJob(string $type, DnsRecord $record): Job
    {
        $domain = $record->getDomain();
        $zoneName = $this->recordHelper->normalizeZoneName($domain->getName());
        $payload = [
            'record_id' => (string) ($record->getId() ?? ''),
            'domain_id' => (string) $domain->getId(),
            'zone_name' => $zoneName,
            'record_name' => $this->recordHelper->buildRecordName($record->getName(), $zoneName),
            'type' => $record->getType(),
            'content' => $this->recordHelper->buildRecordContent($record->getType(), $record->getContent(), $record->getPriority()),
            'ttl' => (string) $record->getTtl(),
        ];

        if ($record->getPriority() !== null) {
            $payload['priority'] = (string) $record->getPriority();
        }

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        return $job;
    }
}
