<?php

declare(strict_types=1);

namespace App\Controller\Customer;

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
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
        $webspace = $this->findCustomerWebspace($customer, $webspaceId);

        $job = $this->queueListingJob($webspace, $customer, $path);
        $this->entityManager->flush();

        return new Response($this->twig->render('customer/files/_listing.html.twig', [
            'jobId' => $job->getId(),
            'status' => $job->getStatus()->value,
            'path' => $path,
            'webspaceId' => $webspace->getId(),
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
        $webspaceId = (string) ($payload['webspace_id'] ?? '');

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
            'webspaceId' => $webspaceId,
        ]));
    }

    #[Route(path: '/read', name: 'customer_files_read', methods: ['GET'])]
    public function read(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaceId = (string) $request->query->get('webspace_id', '');
        $path = trim((string) $request->query->get('path', ''));
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing file name.');
        }

        $webspace = $this->findCustomerWebspace($customer, $webspaceId);
        $job = $this->queueFileJob('webspace.files.read', $webspace, $customer, $path, [
            'name' => $name,
        ], 'webspace.files.read_requested');
        $this->entityManager->flush();

        return new Response($this->twig->render('customer/files/_editor.html.twig', [
            'jobId' => $job->getId(),
            'status' => $job->getStatus()->value,
            'path' => $path,
            'name' => $name,
            'webspaceId' => $webspace->getId(),
        ]));
    }

    #[Route(path: '/read/{id}', name: 'customer_files_read_status', methods: ['GET'])]
    public function readStatus(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($request);
        $job = $this->jobRepository->find($id);
        if ($job === null || $job->getType() !== 'webspace.files.read') {
            throw new NotFoundHttpException('Read job not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = (string) ($payload['customer_id'] ?? '');
        if ($payloadCustomerId !== (string) $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $status = $job->getStatus();
        $result = $job->getResult();
        $error = null;
        $content = '';
        $path = (string) ($payload['path'] ?? '');
        $name = (string) ($payload['name'] ?? '');
        $webspaceId = (string) ($payload['webspace_id'] ?? '');

        if ($status === JobStatus::Succeeded && $result !== null) {
            $content = $this->decodeFileContent((string) ($result->getOutput()['content_base64'] ?? ''), $error);
        } elseif ($status === JobStatus::Failed || $status === JobStatus::Cancelled) {
            $error = (string) ($result?->getOutput()['message'] ?? 'File read failed.');
        }

        return new Response($this->twig->render('customer/files/_editor.html.twig', [
            'jobId' => $job->getId(),
            'status' => $status->value,
            'path' => $path,
            'name' => $name,
            'content' => $content,
            'error' => $error,
            'webspaceId' => $webspaceId,
        ]));
    }

    #[Route(path: '/save', name: 'customer_files_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaceId = (string) $request->request->get('webspace_id', '');
        $path = trim((string) $request->request->get('path', ''));
        $name = trim((string) $request->request->get('name', ''));
        $content = (string) $request->request->get('content', '');
        if ($name === '') {
            throw new BadRequestHttpException('Missing file name.');
        }

        $webspace = $this->findCustomerWebspace($customer, $webspaceId);
        $job = $this->queueFileJob('webspace.files.write', $webspace, $customer, $path, [
            'name' => $name,
            'content_base64' => base64_encode($content),
        ], 'webspace.files.write_requested');
        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/files/_action_status.html.twig', [
            'status' => 'queued',
            'message' => 'File save queued.',
        ]));
        $response->headers->set('HX-Trigger', 'files-refresh');

        return $response;
    }

    #[Route(path: '/upload', name: 'customer_files_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaceId = (string) $request->request->get('webspace_id', '');
        $path = trim((string) $request->request->get('path', ''));
        $upload = $request->files->get('upload');
        if (!$upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            throw new BadRequestHttpException('Missing upload.');
        }

        $webspace = $this->findCustomerWebspace($customer, $webspaceId);
        $contents = file_get_contents($upload->getPathname());
        if ($contents === false) {
            throw new BadRequestHttpException('Failed to read upload.');
        }

        $job = $this->queueFileJob('webspace.files.write', $webspace, $customer, $path, [
            'name' => $upload->getClientOriginalName(),
            'content_base64' => base64_encode($contents),
        ], 'webspace.files.upload_requested');
        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/files/_action_status.html.twig', [
            'status' => 'queued',
            'message' => 'Upload queued.',
        ]));
        $response->headers->set('HX-Trigger', 'files-refresh');

        return $response;
    }

    #[Route(path: '/mkdir', name: 'customer_files_mkdir', methods: ['POST'])]
    public function mkdir(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaceId = (string) $request->request->get('webspace_id', '');
        $path = trim((string) $request->request->get('path', ''));
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing folder name.');
        }

        $webspace = $this->findCustomerWebspace($customer, $webspaceId);
        $job = $this->queueFileJob('webspace.files.mkdir', $webspace, $customer, $path, [
            'name' => $name,
        ], 'webspace.files.mkdir_requested');
        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/files/_action_status.html.twig', [
            'status' => 'queued',
            'message' => 'Folder creation queued.',
        ]));
        $response->headers->set('HX-Trigger', 'files-refresh');

        return $response;
    }

    #[Route(path: '/delete', name: 'customer_files_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaceId = (string) $request->request->get('webspace_id', '');
        $path = trim((string) $request->request->get('path', ''));
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing target name.');
        }

        $webspace = $this->findCustomerWebspace($customer, $webspaceId);
        $job = $this->queueFileJob('webspace.files.delete', $webspace, $customer, $path, [
            'name' => $name,
        ], 'webspace.files.delete_requested');
        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/files/_action_status.html.twig', [
            'status' => 'queued',
            'message' => 'Delete queued.',
        ]));
        $response->headers->set('HX-Trigger', 'files-refresh');

        return $response;
    }

    #[Route(path: '/download', name: 'customer_files_download', methods: ['GET'])]
    public function download(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $webspaceId = (string) $request->query->get('webspace_id', '');
        $path = trim((string) $request->query->get('path', ''));
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing file name.');
        }

        $webspace = $this->findCustomerWebspace($customer, $webspaceId);
        $job = $this->queueFileJob('webspace.files.read', $webspace, $customer, $path, [
            'name' => $name,
        ], 'webspace.files.download_requested');
        $this->entityManager->flush();

        return new Response($this->twig->render('customer/files/_download.html.twig', [
            'jobId' => $job->getId(),
            'status' => $job->getStatus()->value,
            'name' => $name,
        ]));
    }

    #[Route(path: '/download/{id}', name: 'customer_files_download_status', methods: ['GET'])]
    public function downloadStatus(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($request);
        $job = $this->jobRepository->find($id);
        if ($job === null || $job->getType() !== 'webspace.files.read') {
            throw new NotFoundHttpException('Download job not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = (string) ($payload['customer_id'] ?? '');
        if ($payloadCustomerId !== (string) $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $status = $job->getStatus();
        $name = (string) ($payload['name'] ?? '');
        $error = null;
        if ($status === JobStatus::Failed || $status === JobStatus::Cancelled) {
            $error = (string) ($job->getResult()?->getOutput()['message'] ?? 'Download failed.');
        }

        return new Response($this->twig->render('customer/files/_download.html.twig', [
            'jobId' => $job->getId(),
            'status' => $status->value,
            'name' => $name,
            'error' => $error,
        ]));
    }

    #[Route(path: '/download/{id}/file', name: 'customer_files_download_file', methods: ['GET'])]
    public function downloadFile(Request $request, string $id): Response
    {
        $customer = $this->requireCustomer($request);
        $job = $this->jobRepository->find($id);
        if ($job === null || $job->getType() !== 'webspace.files.read') {
            throw new NotFoundHttpException('Download job not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = (string) ($payload['customer_id'] ?? '');
        if ($payloadCustomerId !== (string) $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        if ($job->getStatus() !== JobStatus::Succeeded) {
            throw new BadRequestHttpException('Download not ready.');
        }

        $result = $job->getResult();
        $error = null;
        $content = $this->decodeFileContent((string) ($result?->getOutput()['content_base64'] ?? ''), $error);
        if ($content === '' && $error !== null) {
            throw new BadRequestHttpException('Download failed.');
        }

        $name = (string) ($payload['name'] ?? 'download.bin');
        $response = new Response($content);
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
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
            'docroot' => $webspace->getDocroot(),
            'php_version' => $webspace->getPhpVersion(),
            'quota' => $webspace->getQuota(),
            'disk_limit_bytes' => $webspace->getDiskLimitBytes(),
            'ftp_enabled' => $webspace->isFtpEnabled(),
            'sftp_enabled' => $webspace->isSftpEnabled(),
            'status' => $webspace->getStatus(),
        ];
    }

    private function findCustomerWebspace(User $customer, string $webspaceId): Webspace
    {
        $webspace = $this->webspaceRepository->find($webspaceId);
        if ($webspace === null || $webspace->getCustomer()->getId() !== $customer->getId()) {
            throw new NotFoundHttpException('Webspace not found.');
        }

        return $webspace;
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

    /**
     * @param array<string, string> $extraPayload
     */
    private function queueFileJob(string $type, Webspace $webspace, User $actor, string $path, array $extraPayload, string $auditEvent): Job
    {
        $payload = array_merge([
            'webspace_id' => (string) ($webspace->getId() ?? ''),
            'customer_id' => (string) $actor->getId(),
            'agent_id' => $webspace->getNode()->getId(),
            'root_path' => $webspace->getPath(),
            'path' => $path,
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, $auditEvent, [
            'job_id' => $job->getId(),
            'webspace_id' => $webspace->getId(),
            'node_id' => $webspace->getNode()->getId(),
            'path' => $path,
            'name' => $extraPayload['name'] ?? null,
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

    private function decodeFileContent(string $encoded, ?string &$error): string
    {
        if ($encoded === '') {
            return '';
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            $error = 'Invalid file content.';
            return '';
        }

        return $decoded;
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
