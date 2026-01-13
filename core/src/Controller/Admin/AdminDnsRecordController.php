<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DnsRecord;
use App\Entity\Job;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\DnsRecordRepository;
use App\Repository\DomainRepository;
use App\Service\AuditLogger;
use App\Service\DnsRecordHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/dns/records')]
final class AdminDnsRecordController
{
    public function __construct(
        private readonly DnsRecordRepository $dnsRecordRepository,
        private readonly DomainRepository $domainRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly DnsRecordHelper $recordHelper,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_dns_records', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $records = $this->dnsRecordRepository->findBy([], ['updatedAt' => 'DESC']);
        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);
        $summary = $this->buildSummary($records);

        return new Response($this->twig->render('admin/dns/records/index.html.twig', [
            'records' => $this->normalizeRecords($records),
            'domains' => $domains,
            'recordTypes' => $this->recordHelper->recordTypes(),
            'summary' => $summary,
            'form' => $this->buildFormContext(),
            'activeNav' => 'dns-records',
        ]));
    }

    #[Route(path: '/table', name: 'admin_dns_records_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $records = $this->dnsRecordRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/dns/records/_table.html.twig', [
            'records' => $this->normalizeRecords($records),
        ]));
    }

    #[Route(path: '/form', name: 'admin_dns_records_form', methods: ['GET'])]
    public function form(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/dns/records/_form.html.twig', [
            'domains' => $domains,
            'recordTypes' => $this->recordHelper->recordTypes(),
            'form' => $this->buildFormContext(),
        ]));
    }

    #[Route(path: '/{id}/edit', name: 'admin_dns_records_edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $record = $this->dnsRecordRepository->find($id);
        if ($record === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);

        return new Response($this->twig->render('admin/dns/records/_form.html.twig', [
            'domains' => $domains,
            'recordTypes' => $this->recordHelper->recordTypes(),
            'form' => $this->buildFormContext($record),
        ]));
    }

    #[Route(path: '', name: 'admin_dns_records_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parsePayload($request);
        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);
        $domain = $formData['domain'];

        if ($domain === null) {
            $formData['errors'][] = 'Domain is required.';
        }

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($domains, $formData, Response::HTTP_BAD_REQUEST);
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

        $response = new Response($this->twig->render('admin/dns/records/_form.html.twig', [
            'domains' => $domains,
            'recordTypes' => $this->recordHelper->recordTypes(),
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'dns-records-changed');

        return $response;
    }

    #[Route(path: '/{id}', name: 'admin_dns_records_update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $record = $this->dnsRecordRepository->find($id);
        if ($record === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $formData = $this->parsePayload($request);
        $domains = $this->domainRepository->findBy([], ['name' => 'ASC']);
        $domain = $formData['domain'];
        if ($domain === null) {
            $formData['errors'][] = 'Domain is required.';
        }

        if ($formData['errors'] !== []) {
            return $this->renderFormWithErrors($domains, $formData, Response::HTTP_BAD_REQUEST, $record);
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

        $response = new Response($this->twig->render('admin/dns/records/_form.html.twig', [
            'domains' => $domains,
            'recordTypes' => $this->recordHelper->recordTypes(),
            'form' => $this->buildFormContext(),
        ]));
        $response->headers->set('HX-Trigger', 'dns-records-changed');

        return $response;
    }

    #[Route(path: '/{id}/delete', name: 'admin_dns_records_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $record = $this->dnsRecordRepository->find($id);
        if ($record === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
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

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('HX-Trigger', 'dns-records-changed');

        return $response;
    }

    private function parsePayload(Request $request): array
    {
        $domainId = $request->request->get('domain_id');
        $name = $this->recordHelper->normalizeName((string) $request->request->get('name', ''));
        $type = strtoupper(trim((string) $request->request->get('type', '')));
        $content = $this->recordHelper->normalizeContent((string) $request->request->get('content', ''), $type);
        $ttlValue = $request->request->get('ttl');
        $priorityValue = $request->request->get('priority');

        $ttl = null;
        if ($ttlValue !== null && $ttlValue !== '') {
            $ttl = is_numeric($ttlValue) ? (int) $ttlValue : null;
        }

        $priority = null;
        if ($priorityValue !== null && $priorityValue !== '') {
            $priority = is_numeric($priorityValue) ? (int) $priorityValue : null;
        }

        $domain = null;
        if (is_numeric($domainId)) {
            $domain = $this->domainRepository->find((int) $domainId);
        }

        $errors = $this->recordHelper->validate($name, $type, $content, $ttl, $priority);

        return [
            'domain' => $domain,
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'ttl' => $ttl ?? 0,
            'priority' => $priority,
            'errors' => $errors,
        ];
    }

    private function buildFormContext(?DnsRecord $record = null, ?array $override = null): array
    {
        $data = [
            'id' => $record?->getId(),
            'domain_id' => $record?->getDomain()->getId(),
            'name' => $record?->getName() ?? '@',
            'type' => $record?->getType() ?? $this->recordHelper->recordTypes()[0],
            'content' => $record?->getContent() ?? '',
            'ttl' => $record?->getTtl() ?? 3600,
            'priority' => $record?->getPriority(),
            'errors' => [],
            'action' => $record === null ? 'create' : 'update',
            'submit_label' => $record === null ? 'Create Record' : 'Update Record',
            'submit_color' => $record === null ? 'bg-indigo-600' : 'bg-amber-500',
            'action_url' => $record === null ? '/admin/dns/records' : sprintf('/admin/dns/records/%d', $record->getId()),
        ];

        if ($override !== null) {
            $data = array_merge($data, $override);
        }

        return $data;
    }

    private function renderFormWithErrors(array $domains, array $formData, int $status, ?DnsRecord $record = null): Response
    {
        $formContext = $this->buildFormContext($record, [
            'domain_id' => $formData['domain']?->getId(),
            'name' => $formData['name'],
            'type' => $formData['type'],
            'content' => $formData['content'],
            'ttl' => $formData['ttl'],
            'priority' => $formData['priority'],
            'errors' => $formData['errors'],
        ]);

        return new Response($this->twig->render('admin/dns/records/_form.html.twig', [
            'domains' => $domains,
            'recordTypes' => $this->recordHelper->recordTypes(),
            'form' => $formContext,
        ]), $status);
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

    /**
     * @param DnsRecord[] $records
     */
    private function buildSummary(array $records): array
    {
        $summary = [
            'total' => count($records),
            'byType' => [],
        ];

        foreach ($records as $record) {
            $type = $record->getType();
            $summary['byType'][$type] = ($summary['byType'][$type] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * @param DnsRecord[] $records
     */
    private function normalizeRecords(array $records): array
    {
        return array_map(function (DnsRecord $record): array {
            return [
                'id' => $record->getId(),
                'domain' => [
                    'id' => $record->getDomain()->getId(),
                    'name' => $record->getDomain()->getName(),
                ],
                'name' => $record->getName(),
                'type' => $record->getType(),
                'content' => $record->getContent(),
                'ttl' => $record->getTtl(),
                'priority' => $record->getPriority(),
                'updatedAt' => $record->getUpdatedAt(),
            ];
        }, $records);
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');

        return $actor instanceof User && $actor->isAdmin();
    }
}
