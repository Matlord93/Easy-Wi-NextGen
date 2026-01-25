<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\JobStatus;
use App\Module\Core\Domain\Enum\InstanceStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\AppSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/instances/{id}/files')]
final class CustomerInstanceFileManagerController
{
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly AppSettingsService $appSettingsService,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_instance_files', methods: ['GET'])]
    public function index(Request $request, int $id): Response
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $path = trim((string) $request->query->get('path', ''));

        return new Response($this->twig->render('customer/instances/files/index.html.twig', [
            'instance' => $this->normalizeInstance($instance),
            'configFiles' => $this->normalizeConfigFiles($instance->getTemplate()->getConfigFiles()),
            'path' => $path,
            'activeNav' => 'instances',
        ]));
    }

    #[Route(path: '/listing', name: 'customer_instance_files_listing', methods: ['GET'])]
    public function listing(Request $request, int $id): Response
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $path = trim((string) $request->query->get('path', ''));

        $job = $this->queueListingJob($instance, $customer, $path);
        $this->entityManager->flush();

        return new Response($this->twig->render('customer/instances/files/_listing.html.twig', [
            'jobId' => $job->getId(),
            'status' => $job->getStatus()->value,
            'path' => $path,
            'instanceId' => $instance->getId(),
        ]));
    }

    #[Route(path: '/listing/{jobId}', name: 'customer_instance_files_listing_status', methods: ['GET'])]
    public function listingStatus(Request $request, int $id, string $jobId): Response
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $job = $this->jobRepository->find($jobId);
        if ($job === null || $job->getType() !== 'instance.files.list') {
            throw new NotFoundHttpException('Listing not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = (string) ($payload['customer_id'] ?? '');
        $payloadInstanceId = (string) ($payload['instance_id'] ?? '');
        if ($payloadCustomerId !== (string) $customer->getId() || $payloadInstanceId !== (string) $instance->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $status = $job->getStatus();
        $result = $job->getResult();
        $entries = [];
        $error = null;
        $rootPath = (string) ($result?->getOutput()['root_path'] ?? '');
        $path = (string) ($payload['path'] ?? '');

        if ($status === JobStatus::Succeeded && $result !== null) {
            $entries = $this->parseEntries((string) ($result->getOutput()['entries'] ?? ''));
        } elseif ($status === JobStatus::Failed || $status === JobStatus::Cancelled) {
            $error = (string) ($result?->getOutput()['message'] ?? 'Listing failed.');
        }

        return new Response($this->twig->render('customer/instances/files/_listing.html.twig', [
            'jobId' => $job->getId(),
            'status' => $status->value,
            'path' => $path,
            'rootPath' => $rootPath,
            'entries' => $entries,
            'error' => $error,
            'instanceId' => $instance->getId(),
        ]));
    }

    #[Route(path: '/read', name: 'customer_instance_files_read', methods: ['GET'])]
    public function read(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $path = trim((string) $request->query->get('path', ''));
        $name = trim((string) $request->query->get('name', ''));
        $description = trim((string) $request->query->get('description', ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing file name.');
        }

        $job = $this->queueFileJob('instance.files.read', $instance, $customer, $path, [
            'name' => $name,
            'description' => $description,
        ], 'instance.files.read_requested');
        $this->entityManager->flush();

        return new Response($this->twig->render('customer/instances/files/_editor.html.twig', [
            'jobId' => $job->getId(),
            'status' => $job->getStatus()->value,
            'path' => $path,
            'name' => $name,
            'description' => $description,
            'instanceId' => $instance->getId(),
        ]));
    }

    #[Route(path: '/read/{jobId}', name: 'customer_instance_files_read_status', methods: ['GET'])]
    public function readStatus(Request $request, int $id, string $jobId): Response
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $job = $this->jobRepository->find($jobId);
        if ($job === null || $job->getType() !== 'instance.files.read') {
            throw new NotFoundHttpException('Read job not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = (string) ($payload['customer_id'] ?? '');
        $payloadInstanceId = (string) ($payload['instance_id'] ?? '');
        if ($payloadCustomerId !== (string) $customer->getId() || $payloadInstanceId !== (string) $instance->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $status = $job->getStatus();
        $result = $job->getResult();
        $error = null;
        $content = '';
        $path = (string) ($payload['path'] ?? '');
        $name = (string) ($payload['name'] ?? '');
        $description = (string) ($payload['description'] ?? '');

        if ($status === JobStatus::Succeeded && $result !== null) {
            $content = $this->decodeFileContent((string) ($result->getOutput()['content_base64'] ?? ''), $error);
        } elseif ($status === JobStatus::Failed || $status === JobStatus::Cancelled) {
            $error = (string) ($result?->getOutput()['message'] ?? 'File read failed.');
        }

        return new Response($this->twig->render('customer/instances/files/_editor.html.twig', [
            'jobId' => $job->getId(),
            'status' => $status->value,
            'path' => $path,
            'name' => $name,
            'description' => $description,
            'content' => $content,
            'error' => $error,
            'instanceId' => $instance->getId(),
        ]));
    }

    #[Route(path: '/save', name: 'customer_instance_files_save', methods: ['POST'])]
    public function save(Request $request, int $id): Response
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $this->assertInstanceRunning($instance);
        $path = trim((string) $request->request->get('path', ''));
        $name = trim((string) $request->request->get('name', ''));
        $content = (string) $request->request->get('content', '');
        if ($name === '') {
            throw new BadRequestHttpException('Missing file name.');
        }

        $job = $this->queueFileJob('instance.files.write', $instance, $customer, $path, [
            'name' => $name,
            'content_base64' => base64_encode($content),
        ], 'instance.files.write_requested');
        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/instances/files/_action_status.html.twig', [
            'status' => 'queued',
            'message' => 'File save queued.',
        ]));
        $response->headers->set('HX-Trigger', 'instance-files-refresh');

        return $response;
    }

    #[Route(path: '/upload', name: 'customer_instance_files_upload', methods: ['POST'])]
    public function upload(Request $request, int $id): Response
    {
        $this->assertDataManagerEnabled();
        $this->assertFilePushEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $this->assertInstanceRunning($instance);
        $path = trim((string) $request->request->get('path', ''));
        $upload = $request->files->get('upload');
        if (!$upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            throw new BadRequestHttpException('Missing upload.');
        }

        $contents = file_get_contents($upload->getPathname());
        if ($contents === false) {
            throw new BadRequestHttpException('Failed to read upload.');
        }

        $job = $this->queueFileJob('instance.files.write', $instance, $customer, $path, [
            'name' => $upload->getClientOriginalName(),
            'content_base64' => base64_encode($contents),
        ], 'instance.files.upload_requested');
        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/instances/files/_action_status.html.twig', [
            'status' => 'queued',
            'message' => 'Upload queued.',
        ]));
        $response->headers->set('HX-Trigger', 'instance-files-refresh');

        return $response;
    }

    #[Route(path: '/mkdir', name: 'customer_instance_files_mkdir', methods: ['POST'])]
    public function mkdir(Request $request, int $id): Response
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $path = trim((string) $request->request->get('path', ''));
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing folder name.');
        }

        $job = $this->queueFileJob('instance.files.mkdir', $instance, $customer, $path, [
            'name' => $name,
        ], 'instance.files.mkdir_requested');
        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/instances/files/_action_status.html.twig', [
            'status' => 'queued',
            'message' => 'Folder creation queued.',
        ]));
        $response->headers->set('HX-Trigger', 'instance-files-refresh');

        return $response;
    }

    #[Route(path: '/delete', name: 'customer_instance_files_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $path = trim((string) $request->request->get('path', ''));
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing target name.');
        }

        $job = $this->queueFileJob('instance.files.delete', $instance, $customer, $path, [
            'name' => $name,
        ], 'instance.files.delete_requested');
        $this->entityManager->flush();

        $response = new Response($this->twig->render('customer/instances/files/_action_status.html.twig', [
            'status' => 'queued',
            'message' => 'Delete queued.',
        ]));
        $response->headers->set('HX-Trigger', 'instance-files-refresh');

        return $response;
    }

    #[Route(path: '/download', name: 'customer_instance_files_download', methods: ['GET'])]
    public function download(Request $request, int $id): Response
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $path = trim((string) $request->query->get('path', ''));
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing file name.');
        }

        $job = $this->queueFileJob('instance.files.read', $instance, $customer, $path, [
            'name' => $name,
        ], 'instance.files.download_requested');
        $this->entityManager->flush();

        return new Response($this->twig->render('customer/instances/files/_download.html.twig', [
            'jobId' => $job->getId(),
            'status' => $job->getStatus()->value,
            'name' => $name,
            'instanceId' => $instance->getId(),
        ]));
    }

    #[Route(path: '/download/{jobId}', name: 'customer_instance_files_download_status', methods: ['GET'])]
    public function downloadStatus(Request $request, int $id, string $jobId): Response
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $job = $this->jobRepository->find($jobId);
        if ($job === null || $job->getType() !== 'instance.files.read') {
            throw new NotFoundHttpException('Download job not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = (string) ($payload['customer_id'] ?? '');
        $payloadInstanceId = (string) ($payload['instance_id'] ?? '');
        if ($payloadCustomerId !== (string) $customer->getId() || $payloadInstanceId !== (string) $instance->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        $status = $job->getStatus();
        $name = (string) ($payload['name'] ?? '');
        $error = null;
        if ($status === JobStatus::Failed || $status === JobStatus::Cancelled) {
            $error = (string) ($job->getResult()?->getOutput()['message'] ?? 'Download failed.');
        }

        return new Response($this->twig->render('customer/instances/files/_download.html.twig', [
            'jobId' => $job->getId(),
            'status' => $status->value,
            'name' => $name,
            'error' => $error,
            'instanceId' => $instance->getId(),
        ]));
    }

    #[Route(path: '/download/{jobId}/file', name: 'customer_instance_files_download_file', methods: ['GET'])]
    public function downloadFile(Request $request, int $id, string $jobId): Response
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $job = $this->jobRepository->find($jobId);
        if ($job === null || $job->getType() !== 'instance.files.read') {
            throw new NotFoundHttpException('Download job not found.');
        }

        $payload = $job->getPayload();
        $payloadCustomerId = (string) ($payload['customer_id'] ?? '');
        $payloadInstanceId = (string) ($payload['instance_id'] ?? '');
        if ($payloadCustomerId !== (string) $customer->getId() || $payloadInstanceId !== (string) $instance->getId()) {
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

    private function findCustomerInstance(User $customer, int $id): Instance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException('Instance not found.');
        }

        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    private function normalizeInstance(Instance $instance): array
    {
        return [
            'id' => $instance->getId(),
            'name' => $instance->getTemplate()->getDisplayName(),
            'server_name' => $instance->getServerName(),
            'game_key' => $instance->getTemplate()->getGameKey(),
            'node' => [
                'id' => $instance->getNode()->getId(),
                'name' => $instance->getNode()->getName(),
            ],
        ];
    }

    private function normalizeConfigFiles(array $configFiles): array
    {
        $normalized = [];
        foreach ($configFiles as $entry) {
            $path = trim((string) ($entry['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $description = trim((string) ($entry['description'] ?? ''));
            $name = basename($path);
            $dir = dirname($path);
            $normalized[] = [
                'path' => $path,
                'dir' => $dir === '.' ? '' : $dir,
                'name' => $name,
                'description' => $description,
            ];
        }

        return $normalized;
    }

    private function queueListingJob(Instance $instance, User $actor, string $path): Job
    {
        $payload = [
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $actor->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'path' => $path,
        ];

        $job = new Job('instance.files.list', $payload);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, 'instance.files.list_requested', [
            'job_id' => $job->getId(),
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'path' => $path,
        ]);

        return $job;
    }

    /**
     * @param array<string, string> $extraPayload
     */
    private function queueFileJob(string $type, Instance $instance, User $actor, string $path, array $extraPayload, string $auditEvent): Job
    {
        $payload = array_merge([
            'instance_id' => (string) ($instance->getId() ?? ''),
            'customer_id' => (string) $actor->getId(),
            'agent_id' => $instance->getNode()->getId(),
            'path' => $path,
        ], $extraPayload);

        $job = new Job($type, $payload);
        $this->entityManager->persist($job);

        $this->auditLogger->log($actor, $auditEvent, [
            'job_id' => $job->getId(),
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
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

    private function assertDataManagerEnabled(): void
    {
        if (!$this->appSettingsService->isCustomerDataManagerEnabled()) {
            throw new AccessDeniedHttpException('File manager is disabled.');
        }
    }

    private function assertFilePushEnabled(): void
    {
        if (!$this->appSettingsService->isCustomerFilePushEnabled()) {
            throw new AccessDeniedHttpException('File uploads are disabled.');
        }
    }

    private function assertInstanceRunning(Instance $instance): void
    {
        if ($instance->getStatus() !== InstanceStatus::Running) {
            throw new BadRequestHttpException('Instance must be running to send data.');
        }
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
