<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DiskEnforcementService;
use App\Module\Core\Application\Exception\FileServiceException;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Repository\InstanceRepository;
use App\Repository\JobRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Module\Core\Attribute\RequiresModule;

#[RequiresModule('game')]
final class CustomerInstanceFileApiController
{
    private const int EDITOR_MAX_BYTES = 1_048_576;
    private const int UPLOAD_MAX_BYTES = 104_857_600;
    private const int LIST_DEFAULT_LIMIT = 1000;
    private const int LIST_MAX_LIMIT = 5000;
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly JobRepository $jobRepository,
        private readonly FileServiceClient $fileService,
        private readonly AuditLogger $auditLogger,
        private readonly AppSettingsService $appSettingsService,
        private readonly DiskEnforcementService $diskEnforcementService,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.instance_files_uploads')]
        private readonly RateLimiterFactory $uploadsLimiter,
        #[Autowire(service: 'limiter.instance_files_commands')]
        private readonly RateLimiterFactory $commandsLimiter,
        private readonly ResponseEnvelopeFactory $responseEnvelopeFactory,
        private readonly TranslatorInterface $translator,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $kernelDebug = false,
    ) {
    }

    #[Route(path: '/api/instances/{id}/files', name: 'customer_instance_files_api_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files', name: 'customer_instance_files_api_list_v1', methods: ['GET'])]
    public function list(Request $request, int $id): JsonResponse
    {
        $startedAt = microtime(true);
        $source = 'agent';
        $statusCode = JsonResponse::HTTP_OK;
        try {
            $this->assertDataManagerEnabled($request);
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($request, $customer, $id);
            $path = trim((string) $request->query->get('path', ''));
            $limit = $this->parseListLimit($request);
            $offset = $this->parseListOffset($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->handleHttpError($request, $exception);
        }

        try {
            $listing = $this->fileService->list($instance, $path, $limit, $offset);
        } catch (\RuntimeException $exception) {
            return $this->handleListingError($request, $customer->getId(), $instance->getId(), $path, $source, $exception, $startedAt, $this->canExposeTechnicalDetails($customer));
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->logger->info('instance.files.list', [
            'request_id' => $this->getRequestId($request),
            'user_id' => $customer->getId(),
            'instance_id' => $instance->getId(),
            'path' => $path,
            'source' => $source,
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
        ]);

        $exposeTechnicalDetails = $this->canExposeTechnicalDetails($customer);
        $warnings = $this->normalizeListingWarnings($listing['warnings'] ?? [], $exposeTechnicalDetails);
        if ($warnings !== []) {
            $this->logger->warning('instance.files.list_warnings', [
                'request_id' => $this->getRequestId($request),
                'user_id' => $customer->getId(),
                'instance_id' => $instance->getId(),
                'path' => $path,
                'warning_count' => count($warnings),
                'warnings' => $warnings,
            ]);
        }

        $files = array_map(fn (array $entry) => $this->normalizeEntry($entry, $exposeTechnicalDetails), $listing['entries']);

        return $this->jsonResponse($request, [
            'root_path' => $exposeTechnicalDetails ? $listing['root_path'] : '',
            'path' => $listing['path'],
            'cwd' => $listing['path'],
            'entries' => $files,
            'files' => $files,
            'warnings' => $warnings,
            'total' => (int) ($listing['total'] ?? count($files)),
            'offset' => (int) ($listing['offset'] ?? 0),
            'limit' => (int) ($listing['limit'] ?? self::LIST_DEFAULT_LIMIT),
            'truncated' => (bool) ($listing['truncated'] ?? false),
            'request_id' => $this->getRequestId($request),
        ]);
    }

    #[Route(path: '/api/instances/{id}/files/health', name: 'customer_instance_files_api_health', methods: ['GET'])]
    public function health(Request $request, int $id): JsonResponse
    {
        try {
            $this->assertDataManagerEnabled($request);
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($request, $customer, $id);
            $listing = $this->fileService->list($instance, '', 1, 0);

            return $this->jsonResponse($request, [
                'ok' => true,
                'cwd' => $listing['path'] ?? '',
                'warnings' => $this->normalizeListingWarnings($listing['warnings'] ?? [], $this->canExposeTechnicalDetails($customer)),
                'request_id' => $this->getRequestId($request),
            ]);
        } catch (\RuntimeException $exception) {
            $errorCode = $exception instanceof FileServiceException
                ? $this->normalizeFileErrorCode($exception->getErrorCode())
                : 'health_failed';

            return $this->errorResponse(
                $request,
                $errorCode,
                $exception->getMessage(),
                $exception instanceof FileServiceException ? $exception->getStatusCode() : JsonResponse::HTTP_BAD_GATEWAY,
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->handleHttpError($request, $exception);
        }
    }

    #[Route(path: '/api/instances/{id}/files/diagnostics', name: 'customer_instance_files_api_diagnostics', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/diagnostics', name: 'customer_instance_files_api_diagnostics_v1', methods: ['GET'])]
    public function diagnostics(Request $request, int $id): JsonResponse
    {
        try {
            $this->assertDataManagerEnabled($request);
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($request, $customer, $id);
            $path = trim((string) $request->query->get('path', ''));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->handleHttpError($request, $exception);
        }

        $exposeTechnicalDetails = $this->canExposeTechnicalDetails($customer);
        $agent = $this->runDiagnosticsProbe(
            'agent',
            fn () => $this->fileService->list($instance, $path, self::LIST_DEFAULT_LIMIT, 0),
            $exposeTechnicalDetails,
        );

        return $this->jsonResponse($request, [
            'agent' => $agent,
            'request_id' => $this->getRequestId($request),
        ]);
    }

    #[Route(path: '/api/instances/{id}/files/download', name: 'customer_instance_files_api_download', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/download', name: 'customer_instance_files_api_download_v1', methods: ['GET'])]
    public function download(Request $request, int $id): Response
    {
        $this->assertDataManagerEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);
        if ($blocked = $this->assertMutableFilesActionAllowed($request, $instance)) {
            return $blocked;
        }
        $path = trim((string) $request->query->get('path', ''));
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_file_name'));
        }

        try {
            $content = $this->fileService->downloadFile($instance, $path, $name);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'download', $customer->getId(), $instance->getId(), $path, $exception, [
                'name' => $name,
            ]);
        }

        $response = new Response($content);
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route(path: '/api/instances/{id}/files/read', name: 'customer_instance_files_api_read', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/read', name: 'customer_instance_files_api_read_v1', methods: ['GET'])]
    public function read(Request $request, int $id): Response
    {
        $this->assertDataManagerEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);
        if ($blocked = $this->assertMutableFilesActionAllowed($request, $instance)) {
            return $blocked;
        }
        $path = trim((string) $request->query->get('path', ''));
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_file_name'));
        }

        try {
            $content = $this->fileService->readFileForEditor($instance, $path, $name);
            $this->assertEditorContent($request, $content);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'read', $customer->getId(), $instance->getId(), $path, $exception, [
                'name' => $name,
            ]);
        }

        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route(path: '/api/instances/{id}/files/content', name: 'customer_instance_files_api_content', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/content', name: 'customer_instance_files_api_content_v1', methods: ['GET'])]
    public function content(Request $request, int $id): JsonResponse
    {
        try {
            $this->assertDataManagerEnabled($request);
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($request, $customer, $id);
            $path = trim((string) $request->query->get('path', ''));
            if ($path === '') {
                throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_file_path'));
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->handleHttpError($request, $exception);
        }

        try {
            $payload = $this->fileService->readFileContent($instance, $path);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'content', $customer->getId(), $instance->getId(), $path, $exception);
        }

        return $this->jsonResponse($request, [
            'path' => $payload['path'],
            'content' => $payload['content'],
            'encoding' => $payload['encoding'],
            'is_binary' => $payload['is_binary'],
            'size' => $payload['size'],
            'etag' => $payload['etag'],
            'request_id' => $this->getRequestId($request),
        ]);
    }

    #[Route(path: '/api/instances/{id}/files/content', name: 'customer_instance_files_api_content_save', methods: ['PUT'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/content', name: 'customer_instance_files_api_content_save_v1', methods: ['PUT'])]
    public function saveContent(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);
        if ($blocked = $this->assertMutableFilesActionAllowed($request, $instance)) {
            return $blocked;
        }

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse($request);
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $content = (string) ($payload['content'] ?? '');
        $etag = isset($payload['etag']) ? trim((string) $payload['etag']) : null;
        if ($path === '') {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_file_path'));
        }

        try {
            $saved = $this->fileService->writeFileContent($instance, $path, $content, $etag);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'content_save', $customer->getId(), $instance->getId(), $path, $exception);
        }

        $this->auditLogger->log($customer, 'instance.files.saved', [
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'path' => $path,
        ]);

        return $this->jsonResponse($request, [
            'path' => $saved['path'],
            'size' => $saved['size'],
            'saved' => $saved['saved'],
            'new_etag' => $saved['new_etag'],
            'request_id' => $this->getRequestId($request),
        ]);
    }

    #[Route(path: '/api/instances/{id}/files/upload', name: 'customer_instance_files_api_upload', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/upload', name: 'customer_instance_files_api_upload_v1', methods: ['POST'])]
    public function upload(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled($request);
        $this->assertFilePushEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);
        if ($blocked = $this->assertMutableFilesActionAllowed($request, $instance)) {
            return $blocked;
        }

        if (!$this->consumeLimiter($this->uploadsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse($request);
        }

        $path = trim((string) $request->request->get('path', ''));
        $upload = $request->files->get('upload');
        if (!$upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_upload'));
        }

        $uploadBytes = $this->resolveUploadBytes($request, $upload);
        $maxUploadBytes = $this->getMaxUploadBytes();
        if ($uploadBytes > $maxUploadBytes) {
            return $this->errorResponse(
                $request,
                'file_too_large',
                $this->t($request, 'customer_instance_files_error_upload_too_large', ['%max_bytes%' => (string) $maxUploadBytes]),
                JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE,
                [
                    'max_bytes' => $maxUploadBytes,
                    'upload_bytes' => $uploadBytes,
                ],
            );
        }

        $quotaBlock = $this->diskEnforcementService->guardUpload($instance, $uploadBytes);
        if ($quotaBlock !== null) {
            return $this->errorResponse(
                $request,
                'disk_quota_exceeded',
                $quotaBlock,
                JsonResponse::HTTP_CONFLICT,
                [
                    'disk_state' => $instance->getDiskState()->value,
                    'upload_bytes' => $uploadBytes,
                ],
            );
        }

        if ($this->isProtectedFilename($upload->getClientOriginalName())) {
            return $this->errorResponse($request, 'files_protected', $this->t($request, 'customer_instance_files_error_file_protected'), JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $this->fileService->uploadFile($instance, $path, $upload);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'upload', $customer->getId(), $instance->getId(), $path, $exception, [
                'name' => $upload->getClientOriginalName(),
            ]);
        }

        $this->auditLogger->log($customer, 'instance.files.uploaded', [
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'path' => $path,
            'name' => $upload->getClientOriginalName(),
        ]);

        return $this->jsonResponse($request, ['status' => 'ok'], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/instances/{id}/files/save', name: 'customer_instance_files_api_save', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/save', name: 'customer_instance_files_api_save_v1', methods: ['POST'])]
    public function save(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);
        if ($blocked = $this->assertMutableFilesActionAllowed($request, $instance)) {
            return $blocked;
        }

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse($request);
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $content = (string) ($payload['content'] ?? '');
        if ($name === '') {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_file_name'));
        }
        if ($this->isProtectedFilename($name)) {
            return $this->errorResponse($request, 'files_protected', $this->t($request, 'customer_instance_files_error_file_protected'), JsonResponse::HTTP_FORBIDDEN);
        }

        $quotaBlock = $this->diskEnforcementService->guardUpload($instance, strlen($content));
        if ($quotaBlock !== null) {
            return $this->errorResponse(
                $request,
                'disk_quota_exceeded',
                $quotaBlock,
                JsonResponse::HTTP_CONFLICT,
                [
                    'disk_state' => $instance->getDiskState()->value,
                    'upload_bytes' => strlen($content),
                ],
            );
        }

        try {
            $this->assertEditorContent($request, $content);
            $this->fileService->writeFile($instance, $path, $name, $content);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'save', $customer->getId(), $instance->getId(), $path, $exception, [
                'name' => $name,
            ]);
        }

        $this->auditLogger->log($customer, 'instance.files.saved', [
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'path' => $path,
            'name' => $name,
        ]);

        return $this->jsonResponse($request, ['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/mkdir', name: 'customer_instance_files_api_mkdir', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/mkdir', name: 'customer_instance_files_api_mkdir_v1', methods: ['POST'])]
    public function mkdir(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);
        if ($blocked = $this->assertMutableFilesActionAllowed($request, $instance)) {
            return $blocked;
        }

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse($request);
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_folder_name'));
        }

        try {
            $this->fileService->makeDirectory($instance, $path, $name);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'mkdir', $customer->getId(), $instance->getId(), $path, $exception, [
                'name' => $name,
            ]);
        }

        $this->auditLogger->log($customer, 'instance.files.mkdir', [
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'path' => $path,
            'name' => $name,
        ]);

        return $this->jsonResponse($request, ['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/rename', name: 'customer_instance_files_api_rename', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/rename', name: 'customer_instance_files_api_rename_v1', methods: ['POST'])]
    public function rename(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);
        if ($blocked = $this->assertMutableFilesActionAllowed($request, $instance)) {
            return $blocked;
        }

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse($request);
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $newName = trim((string) ($payload['new_name'] ?? ''));
        if ($name === '' || $newName === '') {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_rename_target'));
        }
        if ($this->isProtectedFilename($name) || $this->isProtectedFilename($newName)) {
            return $this->errorResponse($request, 'files_protected', $this->t($request, 'customer_instance_files_error_file_protected'), JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $this->fileService->rename($instance, $path, $name, $newName);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'rename', $customer->getId(), $instance->getId(), $path, $exception, [
                'name' => $name,
                'new_name' => $newName,
            ]);
        }

        $this->auditLogger->log($customer, 'instance.files.rename', [
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'path' => $path,
            'name' => $name,
            'new_name' => $newName,
        ]);

        return $this->jsonResponse($request, ['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/delete', name: 'customer_instance_files_api_delete', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/delete', name: 'customer_instance_files_api_delete_v1', methods: ['POST'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse($request);
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_target_name'));
        }
        if ($this->isProtectedFilename($name)) {
            return $this->errorResponse($request, 'files_protected', $this->t($request, 'customer_instance_files_error_file_protected'), JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $this->fileService->delete($instance, $path, $name);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'delete', $customer->getId(), $instance->getId(), $path, $exception, [
                'name' => $name,
            ]);
        }

        $this->auditLogger->log($customer, 'instance.files.delete', [
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'path' => $path,
            'name' => $name,
        ]);

        return $this->jsonResponse($request, ['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/chmod', name: 'customer_instance_files_api_chmod', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/chmod', name: 'customer_instance_files_api_chmod_v1', methods: ['POST'])]
    public function chmod(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse($request);
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $modeValue = $payload['mode'] ?? null;
        if ($name === '' || $modeValue === null || $modeValue === '') {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_permissions'));
        }

        $mode = $this->parseMode($modeValue);
        if ($mode === null) {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_invalid_permissions'));
        }

        try {
            $this->fileService->chmod($instance, $path, $name, $mode);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'chmod', $customer->getId(), $instance->getId(), $path, $exception, [
                'name' => $name,
                'mode' => $mode,
            ]);
        }

        $this->auditLogger->log($customer, 'instance.files.chmod', [
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'path' => $path,
            'name' => $name,
            'mode' => $mode,
        ]);

        return $this->jsonResponse($request, ['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/extract', name: 'customer_instance_files_api_extract', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/extract', name: 'customer_instance_files_api_extract_v1', methods: ['POST'])]
    public function extract(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled($request);
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($request, $customer, $id);

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse($request);
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $destination = trim((string) ($payload['destination'] ?? ''));
        if ($name === '') {
            throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_missing_archive_name'));
        }

        $blockMessage = $this->diskEnforcementService->guardInstanceAction($instance, new \DateTimeImmutable());
        if ($blockMessage !== null) {
            return $this->errorResponse(
                $request,
                'disk_quota_exceeded',
                $blockMessage,
                JsonResponse::HTTP_CONFLICT,
                ['disk_state' => $instance->getDiskState()->value],
            );
        }

        try {
            $this->fileService->extract($instance, $path, $name, $destination);
        } catch (\RuntimeException $exception) {
            return $this->handleActionError($request, 'extract', $customer->getId(), $instance->getId(), $path, $exception, [
                'name' => $name,
                'destination' => $destination,
            ]);
        }

        $this->auditLogger->log($customer, 'instance.files.extract', [
            'instance_id' => $instance->getId(),
            'node_id' => $instance->getNode()->getId(),
            'path' => $path,
            'name' => $name,
            'destination' => $destination,
        ]);

        return $this->jsonResponse($request, ['status' => 'ok']);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (
            !$actor instanceof User
            || (!$actor->isAdmin() && $actor->getType() !== UserType::Customer)
        ) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', $this->t($request, 'customer_instance_files_error_unauthorized'));
        }

        return $actor;
    }

    private function findCustomerInstance(Request $request, User $customer, int $id): Instance
    {
        $instance = $this->instanceRepository->find($id);
        if ($instance === null) {
            throw new NotFoundHttpException($this->t($request, 'customer_instance_files_error_instance_not_found'));
        }

        if (!$customer->isAdmin() && $instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException($this->t($request, 'customer_instance_files_error_forbidden'));
        }

        return $instance;
    }

    private function parsePayload(Request $request): array
    {
        if ($request->getContentTypeFormat() === 'json') {
            try {
                return $request->toArray();
            } catch (\JsonException $exception) {
                throw new BadRequestHttpException($this->t($request, 'customer_instance_files_error_invalid_json'), $exception);
            }
        }

        return $request->request->all();
    }

    private function parseMode(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[0-7]{3,4}$/', $value)) {
            return intval($value, 8);
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function assertMutableFilesActionAllowed(Request $request, Instance $instance): ?JsonResponse
    {
        $active = $this->jobRepository->findLatestActiveByTypesAndInstanceId([
            'instance.start',
            'instance.stop',
            'instance.restart',
            'instance.reinstall',
            'instance.backup.create',
            'instance.backup.restore',
            'instance.config.apply',
            'instance.settings.update',
        ], (int) ($instance->getId() ?? 0));
        if (!$active instanceof Job) {
            return null;
        }

        return $this->errorResponse(
            $request,
            'files_action_blocked',
            'Files action blocked while lifecycle operation is running.',
            JsonResponse::HTTP_CONFLICT,
            [
                'active_job_id' => $active->getId(),
                'active_job_type' => $active->getType(),
            ],
        );
    }

    private function handleListingError(
        Request $request,
        int $userId,
        int $instanceId,
        string $path,
        string $source,
        \RuntimeException $exception,
        float $startedAt,
        bool $exposeTechnicalDetails = false,
    ): JsonResponse {
        $requestId = $this->getRequestId($request);
        $statusCode = JsonResponse::HTTP_BAD_GATEWAY;
        $errorCode = 'files_listing_failed';
        $details = [];

        if ($exception instanceof FileServiceException) {
            $statusCode = $exception->getStatusCode();
            $errorCode = $this->normalizeFileErrorCode($exception->getErrorCode());
            $details = $exception->getDetails();
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->logger->warning('instance.files.list', [
            'request_id' => $requestId,
            'user_id' => $userId,
            'instance_id' => $instanceId,
            'path' => $path,
            'source' => $source,
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'error_code' => $errorCode,
            'error' => $exception->getMessage(),
        ]);

        return $this->errorResponse($request, $errorCode, $exception->getMessage(), $statusCode, $this->filterErrorDetails($details, $exposeTechnicalDetails));
    }

    /**
     * @param callable(): array{root_path: string, path: string, entries: array<int, array{name: string, size: int, mode: string, modified_at: string, is_dir: bool}>} $probe
     * @return array<string, mixed>
     */
    private function runDiagnosticsProbe(string $source, callable $probe, bool $exposeTechnicalDetails): array
    {
        $startedAt = microtime(true);
        try {
            $listing = $probe();
            return [
                'source' => $source,
                'status' => 'ok',
                'root_path' => $exposeTechnicalDetails ? ($listing['root_path'] ?? '') : '',
                'path' => $listing['path'] ?? '',
                'entry_count' => count($listing['entries'] ?? []),
                'warnings' => $this->normalizeListingWarnings($listing['warnings'] ?? [], $exposeTechnicalDetails),
                'total' => (int) ($listing['total'] ?? count($listing['entries'] ?? [])),
                'truncated' => (bool) ($listing['truncated'] ?? false),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (FileServiceException $exception) {
            return $this->diagnosticsErrorResult($source, $exception, $startedAt, $exposeTechnicalDetails);
        } catch (\RuntimeException $exception) {
            return $this->diagnosticsErrorResult($source, $exception, $startedAt, $exposeTechnicalDetails);
        }
    }

    private function diagnosticsErrorResult(string $source, \RuntimeException $exception, float $startedAt, bool $exposeTechnicalDetails): array
    {
        $statusCode = JsonResponse::HTTP_BAD_GATEWAY;
        $errorCode = 'agent_error';
        $details = [];

        if ($exception instanceof FileServiceException) {
            $statusCode = $exception->getStatusCode();
            $errorCode = $this->normalizeFileErrorCode($exception->getErrorCode());
            $details = $exception->getDetails();
        }

        return [
            'source' => $source,
            'status' => 'error',
            'error_code' => $errorCode,
            'message' => $exception->getMessage(),
            'status_code' => $statusCode,
            'details' => $this->filterErrorDetails($details, $exposeTechnicalDetails),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function handleActionError(
        Request $request,
        string $action,
        int $userId,
        int $instanceId,
        string $path,
        \RuntimeException $exception,
        array $details = [],
    ): JsonResponse {
        $requestId = $this->getRequestId($request);
        $statusCode = JsonResponse::HTTP_BAD_REQUEST;
        $errorCode = 'files_action_failed';
        $errorDetails = $details;

        if ($exception instanceof FileServiceException) {
            $statusCode = $exception->getStatusCode();
            $errorCode = $this->normalizeFileErrorCode($exception->getErrorCode());
            $errorDetails = array_merge($details, $exception->getDetails());
        }

        $this->logger->warning('instance.files.action_failed', [
            'request_id' => $requestId,
            'user_id' => $userId,
            'instance_id' => $instanceId,
            'path' => $path,
            'action' => $action,
            'error_code' => $errorCode,
            'error' => $exception->getMessage(),
            'details' => $errorDetails,
        ]);

        return $this->errorResponse($request, $errorCode, $exception->getMessage(), $statusCode, $errorDetails);
    }


    private function normalizeFileErrorCode(string $errorCode): string
    {
        return match (strtoupper(trim($errorCode))) {
            'INVALID_PATH' => 'invalid_path',
            'PATH_OUTSIDE_INSTANCE_ROOT' => 'path_outside_instance_root',
            'FILE_TOO_LARGE' => 'file_too_large',
            'BINARY_FILE' => 'binary_file',
            'ETAG_MISMATCH' => 'etag_mismatch',
            default => strtolower(trim($errorCode)) !== '' ? strtolower(trim($errorCode)) : 'files_action_failed',
        };
    }

    private function assertEditorContent(Request $request, string $content): void
    {
        if (strlen($content) > self::EDITOR_MAX_BYTES) {
            throw new \RuntimeException($this->t($request, 'customer_instance_files_error_file_too_large', ['%max%' => '1 MB']));
        }

        if (!$this->isTextContent($content)) {
            throw new \RuntimeException($this->t($request, 'customer_instance_files_error_binary_file'));
        }
    }

    private function isTextContent(string $content): bool
    {
        if ($content === '') {
            return true;
        }

        if (str_contains($content, "\0")) {
            return false;
        }

        $sample = substr($content, 0, 2048);
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sample) !== 1;
    }

    private function getRequestId(Request $request): string
    {
        $requestId = $request->headers->get('X-Request-ID');
        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        $attribute = $request->attributes->get('request_id');
        if (is_string($attribute) && $attribute !== '') {
            return $attribute;
        }

        return '';
    }

    private function handleHttpError(Request $request, \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();
        $errorCode = match (true) {
            $exception instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException => 'files_forbidden',
            $exception instanceof \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException => 'files_unauthorized',
            $exception instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => 'files_not_found',
            default => 'files_request_failed',
        };

        return $this->errorResponse($request, $errorCode, $exception->getMessage(), $status);
    }

    private function consumeLimiter(RateLimiterFactory $limiterFactory, Request $request, Instance $instance, User $customer): bool
    {
        $identifier = sprintf('instance-%s-user-%s-%s', $instance->getId(), $customer->getId(), $request->getClientIp() ?? 'local');
        $limiter = $limiterFactory->create($identifier);
        $limit = $limiter->consume(1);

        return $limit->isAccepted();
    }

    private function isProtectedFilename(string $name): bool
    {
        return strtolower(trim($name)) === 'start.sh';
    }

    private function rateLimitResponse(Request $request): JsonResponse
    {
        return $this->errorResponse($request, 'rate_limited', $this->t($request, 'customer_instance_files_error_rate_limited'), JsonResponse::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function errorResponse(Request $request, string $code, string $message, int $statusCode, array $details = []): JsonResponse
    {
        $extra = $details === [] ? [] : ['details' => $details];

        return $this->responseEnvelopeFactory->error(
            $request,
            $message,
            $code,
            $statusCode,
            null,
            $extra,
        );
    }

    private function getMaxUploadBytes(): int
    {
        return self::UPLOAD_MAX_BYTES;
    }

    private function resolveUploadBytes(Request $request, \Symfony\Component\HttpFoundation\File\UploadedFile $upload): int
    {
        $uploadSize = (int) ($upload->getSize() ?? 0);
        if ($uploadSize > 0) {
            return $uploadSize;
        }

        $contentLength = (int) $request->headers->get('Content-Length', '0');
        if ($contentLength > 0) {
            return $contentLength;
        }

        return 0;
    }

    /**
     * @param array{name: string, size: int, mode: string, modified_at: string, is_dir: bool} $entry
     */
    private function normalizeEntry(array $entry, bool $exposeTechnicalDetails = false): array
    {
        $name = (string) ($entry['name'] ?? '');
        if (!$this->isUtf8($name)) {
            $this->logger->warning('instance.files.invalid_utf8_response_name', [
                'entry_error_code' => $entry['error_code'] ?? null,
            ]);
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        $size = (int) ($entry['size'] ?? 0);
        $normalized = [
            'name' => $name,
            'size' => $size,
            'size_human' => $this->formatBytes($size),
            'mode' => (string) ($entry['mode'] ?? '????'),
            'modified_at' => (string) ($entry['modified_at'] ?? ''),
            'is_dir' => (bool) ($entry['is_dir'] ?? false),
            'is_symlink' => (bool) ($entry['is_symlink'] ?? false),
            'link_broken' => (bool) ($entry['link_broken'] ?? false),
            'name_valid_utf8' => (bool) ($entry['name_valid_utf8'] ?? true),
            'metadata_available' => (bool) ($entry['metadata_available'] ?? true),
            'actions_supported' => (bool) ($entry['actions_supported'] ?? true),
            'error_code' => isset($entry['error_code']) ? (string) $entry['error_code'] : null,
        ];

        if ($exposeTechnicalDetails) {
            $normalized['error_message'] = isset($entry['error_message']) ? (string) $entry['error_message'] : null;
            $normalized['link_target'] = isset($entry['link_target']) ? (string) $entry['link_target'] : null;
        }

        return $normalized;
    }


    private function parseListLimit(Request $request): int
    {
        $raw = $request->query->get('limit', self::LIST_DEFAULT_LIMIT);
        $limit = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['default' => self::LIST_DEFAULT_LIMIT]]);

        return max(1, min(self::LIST_MAX_LIMIT, (int) $limit));
    }

    private function parseListOffset(Request $request): int
    {
        $raw = $request->query->get('offset', 0);
        $offset = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);

        return max(0, (int) $offset);
    }

    /**
     * @param array<int, mixed> $warnings
     * @return array<int, array{code:string,message:string,path?:string}>
     */
    private function normalizeListingWarnings(array $warnings, bool $exposeTechnicalDetails): array
    {
        $normalized = [];
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }
            $item = [
                'code' => (string) ($warning['code'] ?? 'LISTING_WARNING'),
                'message' => (string) ($warning['message'] ?? 'A file entry could not be processed completely.'),
            ];
            if (isset($warning['path']) && is_string($warning['path']) && $warning['path'] !== '') {
                $item['path'] = $exposeTechnicalDetails ? $warning['path'] : basename(str_replace('\\', '/', $warning['path']));
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    private function canExposeTechnicalDetails(User $user): bool
    {
        return $this->kernelDebug || $user->isAdmin();
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function filterErrorDetails(array $details, bool $exposeTechnicalDetails): array
    {
        if ($exposeTechnicalDetails) {
            return $details;
        }

        $safe = [];
        foreach (['status_code', 'error_code'] as $key) {
            if (array_key_exists($key, $details)) {
                $safe[$key] = $details[$key];
            }
        }

        return $safe;
    }

    private function isUtf8(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(Request $request, array $payload, int $statusCode = JsonResponse::HTTP_OK): JsonResponse
    {
        try {
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = new JsonResponse(null, $statusCode);
            $response->setEncodingOptions(JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response->setData($payload);

            return $response;
        } catch (\JsonException $exception) {
            $this->logger->error('instance.files.json_encode_failed', [
                'request_id' => $this->getRequestId($request),
                'error' => $exception->getMessage(),
            ]);

            return $this->responseEnvelopeFactory->error(
                $request,
                'File listing response could not be encoded safely.',
                'files_json_encode_failed',
                JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            );
        }
    }

    private function assertDataManagerEnabled(Request $request): void
    {
        if (!$this->appSettingsService->isCustomerDataManagerEnabled()) {
            throw new AccessDeniedHttpException($this->t($request, 'customer_instance_files_error_file_manager_disabled'));
        }
    }

    private function assertFilePushEnabled(Request $request): void
    {
        if (!$this->appSettingsService->isCustomerFilePushEnabled()) {
            throw new AccessDeniedHttpException($this->t($request, 'customer_instance_files_error_uploads_disabled'));
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function t(Request $request, string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'portal', $request->getLocale());
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
