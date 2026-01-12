<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Enum\JobStatus;
use App\Enum\UserType;
use App\Repository\JobRepository;
use App\Service\AuditLogger;
use App\Service\JobPayloadMasker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/jobs')]
final class AdminJobController
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly Environment $twig,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly JobPayloadMasker $jobPayloadMasker,
    ) {
    }

    #[Route(path: '', name: 'admin_jobs', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 25;
        $total = $this->jobRepository->countAll();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $paginated = $this->jobRepository->findPaginatedLatest($page, $perPage);
        $summary = $this->buildSummary();

        return new Response($this->twig->render('admin/jobs/index.html.twig', [
            'activeNav' => 'jobs',
            'jobs' => $this->normalizeJobs($paginated['jobs']),
            'summary' => $summary,
            'pagination' => $this->buildPagination($page, $perPage, $total),
            'activeNav' => 'jobs',
        ]));
    }

    #[Route(path: '/table', name: 'admin_jobs_table', methods: ['GET'])]
    public function table(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 25;
        $total = $this->jobRepository->countAll();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $paginated = $this->jobRepository->findPaginatedLatest($page, $perPage);

        return new Response($this->twig->render('admin/jobs/_table.html.twig', [
            'jobs' => $this->normalizeJobs($paginated['jobs']),
            'pagination' => $this->buildPagination($page, $perPage, $total),
        ]));
    }

    #[Route(path: '/new', name: 'admin_jobs_new', methods: ['GET'])]
    public function createForm(Request $request): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('admin/jobs/create.html.twig', [
            'activeNav' => 'jobs',
            'form' => $this->buildJobFormContext(),
        ]));
    }

    #[Route(path: '', name: 'admin_jobs_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $formData = $this->parseJobPayload($request);
        if ($formData['errors'] !== []) {
            return new Response($this->twig->render('admin/jobs/create.html.twig', [
                'activeNav' => 'jobs',
                'form' => $formData,
            ]), Response::HTTP_BAD_REQUEST);
        }

        $job = new \App\Entity\Job($formData['type'], $formData['payload']);
        $this->entityManager->persist($job);
        $this->auditLogger->log($actor, 'job.created', [
            'job_id' => $job->getId(),
            'type' => $job->getType(),
        ]);
        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/admin/jobs/%s', $job->getId()));
    }

    #[Route(path: '/{id}', name: 'admin_jobs_show', methods: ['GET'], requirements: ['id' => '[0-9a-f]{32}'])]
    public function show(Request $request, string $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $job = $this->jobRepository->find($id);
        if ($job === null) {
            return new Response('Job not found.', Response::HTTP_NOT_FOUND);
        }

        $result = $job->getResult();

        return new Response($this->twig->render('admin/jobs/show.html.twig', [
            'job' => $this->normalizeJob($job),
            'result' => $result ? $this->normalizeResult($result) : null,
            'logLines' => $this->extractLogLines($job),
            'canRetry' => $this->canRetry($job->getStatus()),
        ]));
    }

    #[Route(path: '/{id}/log', name: 'admin_jobs_log', methods: ['GET'], requirements: ['id' => '[0-9a-f]{32}'])]
    public function log(Request $request, string $id): Response
    {
        if (!$this->isAdmin($request)) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $job = $this->jobRepository->find($id);
        if ($job === null) {
            return new Response('Job not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('admin/jobs/_log_tail.html.twig', [
            'logLines' => $this->extractLogLines($job),
        ]));
    }

    #[Route(path: '/{id}/retry', name: 'admin_jobs_retry', methods: ['POST'], requirements: ['id' => '[0-9a-f]{32}'])]
    public function retry(Request $request, string $id): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Admin) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $job = $this->jobRepository->find($id);
        if ($job === null) {
            return new Response('Job not found.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->canRetry($job->getStatus())) {
            return new Response('Job cannot be retried.', Response::HTTP_CONFLICT);
        }

        $retryJob = new \App\Entity\Job($job->getType(), $job->getPayload());

        $this->entityManager->persist($retryJob);
        $this->auditLogger->log($actor, 'job.retry', [
            'job_id' => $job->getId(),
            'retry_job_id' => $retryJob->getId(),
            'type' => $job->getType(),
        ]);
        $this->entityManager->flush();

        return new RedirectResponse(sprintf('/admin/jobs/%s', $retryJob->getId()));
    }

    private function isAdmin(Request $request): bool
    {
        $actor = $request->attributes->get('current_user');
        return $actor instanceof User && $actor->getType() === UserType::Admin;
    }

    private function buildSummary(): array
    {
        return [
            'total' => $this->jobRepository->countAll(),
            'running' => $this->jobRepository->countByStatus(JobStatus::Running),
            'queued' => $this->jobRepository->countByStatus(JobStatus::Queued),
            'failed' => $this->jobRepository->countByStatus(JobStatus::Failed),
        ];
    }

    private function normalizeJobs(array $jobs): array
    {
        return array_map(static function ($job): array {
            return [
                'id' => $job->getId(),
                'type' => $job->getType(),
                'status' => $job->getStatus()->value,
                'createdAt' => $job->getCreatedAt(),
                'lockedBy' => $job->getLockedBy(),
                'lockedAt' => $job->getLockedAt(),
            ];
        }, $jobs);
    }

    private function normalizeJob(\App\Entity\Job $job): array
    {
        return [
            'id' => $job->getId(),
            'type' => $job->getType(),
            'status' => $job->getStatus()->value,
            'payload' => $this->jobPayloadMasker->maskPayload($job->getPayload()),
            'createdAt' => $job->getCreatedAt(),
            'updatedAt' => $job->getUpdatedAt(),
            'lockedBy' => $job->getLockedBy(),
            'lockedAt' => $job->getLockedAt(),
            'lockExpiresAt' => $job->getLockExpiresAt(),
        ];
    }

    private function normalizeResult(\App\Entity\JobResult $result): array
    {
        return [
            'status' => $result->getStatus()->value,
            'completedAt' => $result->getCompletedAt(),
            'output' => $this->jobPayloadMasker->maskValue($result->getOutput()),
        ];
    }

    private function extractLogLines(\App\Entity\Job $job): array
    {
        $result = $job->getResult();
        if ($result === null) {
            return [];
        }

        $output = $this->jobPayloadMasker->maskValue($result->getOutput());
        $lines = [];

        if (isset($output['log']) && is_string($output['log'])) {
            $lines = preg_split('/\r\n|\r|\n/', $output['log']);
        } elseif (isset($output['logs']) && is_array($output['logs'])) {
            $lines = array_values(array_map('strval', $output['logs']));
        } elseif (isset($output['stdout']) || isset($output['stderr'])) {
            foreach (['stdout' => 'STDOUT', 'stderr' => 'STDERR'] as $key => $label) {
                if (isset($output[$key]) && is_string($output[$key]) && $output[$key] !== '') {
                    $sectionLines = preg_split('/\r\n|\r|\n/', $output[$key]);
                    $lines[] = sprintf('--- %s ---', $label);
                    $lines = array_merge($lines, $sectionLines);
                }
            }
        } else {
            $lines = preg_split('/\r\n|\r|\n/', json_encode($output, JSON_PRETTY_PRINT) ?: '');
        }

        $lines = array_values(array_filter(array_map('trim', $lines), static fn ($line) => $line !== ''));

        return array_slice($lines, -200);
    }

    private function buildPagination(int $page, int $perPage, int $total): array
    {
        $totalPages = max(1, (int) ceil($total / $perPage));

        return [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'hasPrevious' => $page > 1,
            'hasNext' => $page < $totalPages,
            'previousPage' => max(1, $page - 1),
            'nextPage' => min($totalPages, $page + 1),
        ];
    }

    private function buildJobFormContext(array $errors = [], ?string $type = null, ?string $payload = null): array
    {
        return [
            'errors' => $errors,
            'type' => $type ?? '',
            'payload' => $payload ?? '',
            'payload_raw' => $payload ?? '',
        ];
    }

    private function parseJobPayload(Request $request): array
    {
        $errors = [];
        $type = trim((string) $request->request->get('type', ''));
        $payloadRaw = trim((string) $request->request->get('payload', ''));

        if ($type === '') {
            $errors[] = 'Job type is required.';
        }

        $payload = [];
        if ($payloadRaw !== '') {
            $payload = json_decode($payloadRaw, true);
            if (!is_array($payload)) {
                $errors[] = 'Payload must be valid JSON.';
                $payload = [];
            }
        }

        return [
            'errors' => $errors,
            'type' => $type,
            'payload' => $payload,
            'payload_raw' => $payloadRaw,
        ];
    }

    private function canRetry(JobStatus $status): bool
    {
        return in_array($status, [JobStatus::Failed, JobStatus::Cancelled, JobStatus::Succeeded], true);
    }
}
