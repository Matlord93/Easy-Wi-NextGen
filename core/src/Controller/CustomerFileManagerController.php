<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Job;
use App\Entity\User;
use App\Entity\Webspace;
use App\Enum\JobStatus;
use App\Enum\UserType;
use App\Repository\JobRepository;
use App\Repository\WebspaceRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/files')]
final class CustomerFileManagerController
{
    public function __construct(
        private readonly WebspaceRepository $webspaceRepository,
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_files', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaces = $this->webspaceRepository->findByCustomer($customer);
        $selectedId = (string) $request->query->get('webspace', '');
        $path = trim((string) $request->query->get('path', ''));
        $selected = $this->resolveSelectedWebspace($webspaces, $selectedId);

        return new Response($this->twig->render('customer/files/index.html.twig', [
            'webspaces' => $this->normalizeWebspaces($webspaces),
            'selectedWebspace' => $selected === null ? null : $this->normalizeWebspace($selected),
            'path' => $path,
            'activeNav' => 'files',
        ]));
    }

    #[Route(path: '/listing', name: 'customer_files_listing', methods: ['GET'])]
    public function listing(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaceId = (string) $request->query->get('webspace_id', '');
        $path = trim((string) $request->query->get('path', ''));
        $webspace = $this->webspaceRepository->find($webspaceId);
        if ($webspace === null || $webspace->getCustomer()->getId() !== $customer->getId()) {
            throw new NotFoundHttpException('Webspace not found.');
        }

        $job = $this->queueListingJob($webspace, $customer, $path);
        $this->entityManager->flush();

        return new Response($this->twig->render('customer/files/_listing.html.twig', [
            'jobId' => $job->getId(),
            'status' => $job->getStatus()->value,
            'path' => $path,
        ]));
    }

    #[Route(path: '/listing/{id}', name: 'customer_files_listing_status', methods: ['GET'])]
    public function listingStatus(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($request);
        $job = $this->jobRepository->find($id);
        if ($job === null || $job->getType() !== 'webspace.files.list') {
            throw new NotFoundHttpException('Listing not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = (string) ($payload['customer_id'] ?? '');
        if ($payloadCustomerId !== (string) $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $status = $job->getStatus();
        $result = $job->getResult();
        $entries = [];
        $error = null;
        $rootPath = (string) ($payload['root_path'] ?? '');
        $path = (string) ($payload['path'] ?? '');

        if ($status === JobStatus::Succeeded && $result !== null) {
            $entries = $this->parseEntries((string) ($result->getOutput()['entries'] ?? ''));
        } elseif ($status === JobStatus::Failed || $status === JobStatus::Cancelled) {
            $error = (string) ($result?->getOutput()['message'] ?? 'Listing failed.');
        }

        return new Response($this->twig->render('customer/files/_listing.html.twig', [
            'jobId' => $job->getId(),
            'status' => $status->value,
            'path' => $path,
            'rootPath' => $rootPath,
            'entries' => $entries,
            'error' => $error,
        ]));
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    /**
     * @param Webspace[] $webspaces
     */
    private function resolveSelectedWebspace(array $webspaces, string $selectedId): ?Webspace
    {
        if ($selectedId !== '') {
            foreach ($webspaces as $webspace) {
                if ((string) $webspace->getId() === $selectedId) {
                    return $webspace;
                }
            }
        }

        return $webspaces[0] ?? null;
    }

    /**
     * @param Webspace[] $webspaces
     */
    private function normalizeWebspaces(array $webspaces): array
    {
        return array_map(fn (Webspace $webspace) => $this->normalizeWebspace($webspace), $webspaces);
    }

    private function normalizeWebspace(Webspace $webspace): array
    {
        return [
            'id' => $webspace->getId(),
            'node' => [
                'id' => $webspace->getNode()->getId(),
                'name' => $webspace->getNode()->getName(),
            ],
            'path' => $webspace->getPath(),
            'php_version' => $webspace->getPhpVersion(),
            'quota' => $webspace->getQuota(),
        ];
    }

    private function queueListingJob(Webspace $webspace, User $actor, string $path): Job
    {
        $payload = [
            'webspace_id' => (string) ($webspace->getId() ?? ''),
            'customer_id' => (string) $actor->getId(),
            'agent_id' => $webspace->getNode()->getId(),
            'root_path' => $webspace->getPath(),
            'path' => $path,
        ];

        $job = new Job('webspace.files.list', $payload);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'webspace.files.list_requested', [
            'job_id' => $job->getId(),
            'webspace_id' => $webspace->getId(),
            'node_id' => $webspace->getNode()->getId(),
            'path' => $path,
        ]);

        return $job;
    }

    private function parseEntries(string $rawEntries): array
    {
        $entries = [];
        if ($rawEntries === '') {
            return $entries;
        }

        foreach (preg_split('/\\r?\\n/', $rawEntries) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line, 5);
            if (count($parts) !== 5) {
                continue;
            }

            [$name, $size, $mode, $modifiedAt, $isDir] = $parts;

            $entries[] = [
                'name' => $name,
                'size' => (int) $size,
                'size_human' => $this->formatBytes((int) $size),
                'mode' => $mode,
                'modified_at' => $modifiedAt,
                'is_dir' => filter_var($isDir, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $entries;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = (int) floor(log($bytes, 1024));
        $value = $bytes / (1024 ** $index);

        return sprintf('%.1f %s', $value, $units[$index] ?? 'B');
    }
}
