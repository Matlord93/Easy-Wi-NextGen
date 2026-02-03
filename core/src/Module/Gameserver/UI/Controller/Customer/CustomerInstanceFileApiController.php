<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Application\Exception\FileServiceException;
use App\Module\Core\Application\Exception\SftpException;
use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InstanceRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\SftpFileService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class CustomerInstanceFileApiController
{
    private const int EDITOR_MAX_BYTES = 1_048_576;
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly FileServiceClient $fileService,
        private readonly SftpFileService $sftpFileService,
        private readonly AuditLogger $auditLogger,
        private readonly AppSettingsService $appSettingsService,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.instance_files_uploads')]
        private readonly RateLimiterFactory $uploadsLimiter,
        #[Autowire(service: 'limiter.instance_files_commands')]
        private readonly RateLimiterFactory $commandsLimiter,
    ) {
    }

    #[Route(path: '/api/instances/{id}/files', name: 'customer_instance_files_api_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files', name: 'customer_instance_files_api_list_v1', methods: ['GET'])]
    public function list(Request $request, int $id): JsonResponse
    {
        $startedAt = microtime(true);
        $source = 'filesvc';
        $statusCode = JsonResponse::HTTP_OK;
        try {
            $this->assertDataManagerEnabled();
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $path = trim((string) $request->query->get('path', ''));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->handleHttpError($request, $exception);
        }

        try {
            $listing = $this->fileService->list($instance, $path);
        } catch (FileServiceException $exception) {
            if (!$this->shouldFallback($exception)) {
                return $this->handleListingError($request, $customer->getId(), $instance->getId(), $path, $source, $exception, $startedAt);
            }
            $source = 'sftp';
            try {
                $listing = $this->sftpFileService->list($instance, $path);
            } catch (SftpException $sftpException) {
                return $this->handleListingError($request, $customer->getId(), $instance->getId(), $path, $source, $sftpException, $startedAt);
            }
        } catch (SftpException $exception) {
            $source = 'sftp';
            return $this->handleListingError($request, $customer->getId(), $instance->getId(), $path, $source, $exception, $startedAt);
        } catch (\RuntimeException $exception) {
            return $this->handleListingError($request, $customer->getId(), $instance->getId(), $path, $source, $exception, $startedAt);
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

        return new JsonResponse([
            'root_path' => $listing['root_path'],
            'path' => $listing['path'],
            'entries' => array_map(fn (array $entry) => $this->normalizeEntry($entry), $listing['entries']),
            'request_id' => $this->getRequestId($request),
        ]);
    }

    #[Route(path: '/api/instances/{id}/files/diagnostics', name: 'customer_instance_files_api_diagnostics', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/diagnostics', name: 'customer_instance_files_api_diagnostics_v1', methods: ['GET'])]
    public function diagnostics(Request $request, int $id): JsonResponse
    {
        try {
            $this->assertDataManagerEnabled();
            $customer = $this->requireCustomer($request);
            $instance = $this->findCustomerInstance($customer, $id);
            $path = trim((string) $request->query->get('path', ''));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            return $this->handleHttpError($request, $exception);
        }

        $filesvc = $this->runDiagnosticsProbe(
            'filesvc',
            fn () => $this->fileService->list($instance, $path),
        );
        $sftp = $this->runDiagnosticsProbe(
            'sftp',
            fn () => $this->sftpFileService->list($instance, $path),
        );

        return new JsonResponse([
            'filesvc' => $filesvc,
            'sftp' => $sftp,
            'request_id' => $this->getRequestId($request),
        ]);
    }

    #[Route(path: '/api/instances/{id}/files/download', name: 'customer_instance_files_api_download', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/download', name: 'customer_instance_files_api_download_v1', methods: ['GET'])]
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

        try {
            $content = $this->withFileFallback(
                fn () => $this->fileService->downloadFile($instance, $path, $name),
                fn () => $this->sftpFileService->readFile($instance, $path, $name),
            );
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
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $path = trim((string) $request->query->get('path', ''));
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing file name.');
        }

        try {
            $content = $this->withFileFallback(
                fn () => $this->fileService->readFileForEditor($instance, $path, $name),
                fn () => $this->sftpFileService->readFileForEditor($instance, $path, $name),
            );
            $this->assertEditorContent($content);
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

    #[Route(path: '/api/instances/{id}/files/upload', name: 'customer_instance_files_api_upload', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/upload', name: 'customer_instance_files_api_upload_v1', methods: ['POST'])]
    public function upload(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled();
        $this->assertFilePushEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if (!$this->consumeLimiter($this->uploadsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse();
        }

        $path = trim((string) $request->request->get('path', ''));
        $upload = $request->files->get('upload');
        if (!$upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            throw new BadRequestHttpException('Missing upload.');
        }
        if ($this->isProtectedFilename($upload->getClientOriginalName())) {
            return new JsonResponse(['error' => 'File is protected.'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $this->withFileFallback(
                fn () => $this->fileService->uploadFile($instance, $path, $upload),
                fn () => $this->uploadViaSftp($instance, $path, $upload),
            );
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

        return new JsonResponse(['status' => 'ok'], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/instances/{id}/files/save', name: 'customer_instance_files_api_save', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/save', name: 'customer_instance_files_api_save_v1', methods: ['POST'])]
    public function save(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse();
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $content = (string) ($payload['content'] ?? '');
        if ($name === '') {
            throw new BadRequestHttpException('Missing file name.');
        }
        if ($this->isProtectedFilename($name)) {
            return new JsonResponse(['error' => 'File is protected.'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $this->assertEditorContent($content);
            $this->withFileFallback(
                fn () => $this->fileService->writeFile($instance, $path, $name, $content),
                fn () => $this->sftpFileService->writeFile($instance, $path, $name, $content),
            );
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

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/mkdir', name: 'customer_instance_files_api_mkdir', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/mkdir', name: 'customer_instance_files_api_mkdir_v1', methods: ['POST'])]
    public function mkdir(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse();
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing folder name.');
        }

        try {
            $this->withFileFallback(
                fn () => $this->fileService->makeDirectory($instance, $path, $name),
                fn () => $this->sftpFileService->makeDirectory($instance, $path, $name),
            );
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

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/rename', name: 'customer_instance_files_api_rename', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/rename', name: 'customer_instance_files_api_rename_v1', methods: ['POST'])]
    public function rename(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse();
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $newName = trim((string) ($payload['new_name'] ?? ''));
        if ($name === '' || $newName === '') {
            throw new BadRequestHttpException('Missing rename target.');
        }
        if ($this->isProtectedFilename($name) || $this->isProtectedFilename($newName)) {
            return new JsonResponse(['error' => 'File is protected.'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $this->withFileFallback(
                fn () => $this->fileService->rename($instance, $path, $name, $newName),
                fn () => $this->sftpFileService->rename($instance, $path, $name, $newName),
            );
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

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/delete', name: 'customer_instance_files_api_delete', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/delete', name: 'customer_instance_files_api_delete_v1', methods: ['POST'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse();
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing target name.');
        }
        if ($this->isProtectedFilename($name)) {
            return new JsonResponse(['error' => 'File is protected.'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $this->withFileFallback(
                fn () => $this->fileService->delete($instance, $path, $name),
                fn () => $this->sftpFileService->delete($instance, $path, $name),
            );
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

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/chmod', name: 'customer_instance_files_api_chmod', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/chmod', name: 'customer_instance_files_api_chmod_v1', methods: ['POST'])]
    public function chmod(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse();
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $modeValue = $payload['mode'] ?? null;
        if ($name === '' || $modeValue === null || $modeValue === '') {
            throw new BadRequestHttpException('Missing permissions.');
        }

        $mode = $this->parseMode($modeValue);
        if ($mode === null) {
            throw new BadRequestHttpException('Invalid permissions.');
        }

        try {
            $this->withFileFallback(
                fn () => $this->fileService->chmod($instance, $path, $name, $mode),
                fn () => $this->sftpFileService->chmod($instance, $path, $name, $mode),
            );
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

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route(path: '/api/instances/{id}/files/extract', name: 'customer_instance_files_api_extract', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/instances/{id}/files/extract', name: 'customer_instance_files_api_extract_v1', methods: ['POST'])]
    public function extract(Request $request, int $id): JsonResponse
    {
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);

        if (!$this->consumeLimiter($this->commandsLimiter, $request, $instance, $customer)) {
            return $this->rateLimitResponse();
        }

        $payload = $this->parsePayload($request);
        $path = trim((string) ($payload['path'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $destination = trim((string) ($payload['destination'] ?? ''));
        if ($name === '') {
            throw new BadRequestHttpException('Missing archive name.');
        }

        try {
            $this->withFileFallback(
                fn () => $this->fileService->extract($instance, $path, $name, $destination),
                fn () => $this->sftpFileService->extract($instance, $path, $name, $destination),
            );
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

        return new JsonResponse(['status' => 'ok']);
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (
            !$actor instanceof User
            || (!$actor->isAdmin() && $actor->getType() !== UserType::Customer)
        ) {
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

        if (!$customer->isAdmin() && $instance->getCustomer()->getId() !== $customer->getId()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $instance;
    }

    private function parsePayload(Request $request): array
    {
        if ($request->getContentTypeFormat() === 'json') {
            try {
                return $request->toArray();
            } catch (\JsonException $exception) {
                throw new BadRequestHttpException('Invalid JSON payload.', $exception);
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

    /**
     * @template T
     * @param callable(): T $primary
     * @param callable(): T $fallback
     * @return T
     */
    private function withFileFallback(callable $primary, callable $fallback)
    {
        try {
            return $primary();
        } catch (\RuntimeException $exception) {
            if (!$this->shouldFallback($exception)) {
                throw $exception;
            }
        }

        return $fallback();
    }

    private function shouldFallback(\RuntimeException $exception): bool
    {
        if ($exception instanceof FileServiceException) {
            return in_array($exception->getErrorCode(), [
                'filesvc_unreachable',
                'filesvc_misconfigured',
                'filesvc_timeout',
            ], true);
        }

        $message = $exception->getMessage();

        return str_contains($message, 'File service unavailable')
            || str_contains($message, 'File service host not configured')
            || str_contains($message, 'File service TLS client certificates are not configured');
    }

    private function handleListingError(
        Request $request,
        int $userId,
        int $instanceId,
        string $path,
        string $source,
        \RuntimeException $exception,
        float $startedAt,
    ): JsonResponse {
        $requestId = $this->getRequestId($request);
        $statusCode = JsonResponse::HTTP_BAD_GATEWAY;
        $errorCode = 'files_listing_failed';
        $details = [];

        if ($exception instanceof FileServiceException) {
            $statusCode = $exception->getStatusCode();
            $errorCode = $exception->getErrorCode();
            $details = $exception->getDetails();
        } elseif ($exception instanceof SftpException) {
            $statusCode = $exception->getStatusCode();
            $errorCode = $exception->getErrorCode();
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

        return new JsonResponse([
            'error_code' => $errorCode,
            'message' => $exception->getMessage(),
            'request_id' => $requestId,
            'details' => $details,
        ], $statusCode);
    }

    /**
     * @param callable(): array{root_path: string, path: string, entries: array<int, array{name: string, size: int, mode: string, modified_at: string, is_dir: bool}>} $probe
     * @return array<string, mixed>
     */
    private function runDiagnosticsProbe(string $source, callable $probe): array
    {
        $startedAt = microtime(true);
        try {
            $listing = $probe();
            return [
                'source' => $source,
                'status' => 'ok',
                'root_path' => $listing['root_path'] ?? '',
                'path' => $listing['path'] ?? '',
                'entry_count' => count($listing['entries'] ?? []),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (FileServiceException $exception) {
            return $this->diagnosticsErrorResult($source, $exception, $startedAt);
        } catch (SftpException $exception) {
            return $this->diagnosticsErrorResult($source, $exception, $startedAt);
        } catch (\RuntimeException $exception) {
            return $this->diagnosticsErrorResult($source, $exception, $startedAt);
        }
    }

    private function diagnosticsErrorResult(string $source, \RuntimeException $exception, float $startedAt): array
    {
        $statusCode = JsonResponse::HTTP_BAD_GATEWAY;
        $errorCode = $source === 'sftp' ? 'sftp_error' : 'filesvc_error';
        $details = [];

        if ($exception instanceof FileServiceException) {
            $statusCode = $exception->getStatusCode();
            $errorCode = $exception->getErrorCode();
            $details = $exception->getDetails();
        } elseif ($exception instanceof SftpException) {
            $statusCode = $exception->getStatusCode();
            $errorCode = $exception->getErrorCode();
            $details = $exception->getDetails();
        }

        return [
            'source' => $source,
            'status' => 'error',
            'error_code' => $errorCode,
            'message' => $exception->getMessage(),
            'status_code' => $statusCode,
            'details' => $details,
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
            $errorCode = $exception->getErrorCode();
            $errorDetails = array_merge($details, $exception->getDetails());
        } elseif ($exception instanceof SftpException) {
            $statusCode = $exception->getStatusCode();
            $errorCode = $exception->getErrorCode();
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

        return new JsonResponse([
            'error_code' => $errorCode,
            'message' => $exception->getMessage(),
            'details' => $errorDetails,
            'request_id' => $requestId,
        ], $statusCode);
    }

    private function assertEditorContent(string $content): void
    {
        if (strlen($content) > self::EDITOR_MAX_BYTES) {
            throw new \RuntimeException('File is too large to edit (max 1 MB).');
        }

        if (!$this->isTextContent($content)) {
            throw new \RuntimeException('Binary files cannot be edited.');
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

        return new JsonResponse([
            'error_code' => $errorCode,
            'message' => $exception->getMessage(),
            'request_id' => $this->getRequestId($request),
        ], $status);
    }

    private function uploadViaSftp(Instance $instance, string $path, \Symfony\Component\HttpFoundation\File\UploadedFile $upload): void
    {
        $contents = file_get_contents($upload->getPathname());
        if ($contents === false) {
            throw new \RuntimeException('Failed to read upload.');
        }

        $this->sftpFileService->writeFile($instance, $path, $upload->getClientOriginalName(), $contents);
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

    private function rateLimitResponse(): JsonResponse
    {
        return new JsonResponse(['error' => 'Too Many Requests.'], JsonResponse::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * @param array{name: string, size: int, mode: string, modified_at: string, is_dir: bool} $entry
     */
    private function normalizeEntry(array $entry): array
    {
        $size = (int) $entry['size'];

        return [
            'name' => $entry['name'],
            'size' => $size,
            'size_human' => $this->formatBytes($size),
            'mode' => $entry['mode'],
            'modified_at' => $entry['modified_at'],
            'is_dir' => (bool) $entry['is_dir'],
        ];
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
