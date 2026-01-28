<?php

declare(strict_types=1);

namespace App\Module\Gameserver\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\Instance;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\InstanceRepository;
use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\FileServiceClient;
use App\Module\Core\Application\SftpFileService;
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
    public function __construct(
        private readonly InstanceRepository $instanceRepository,
        private readonly FileServiceClient $fileService,
        private readonly SftpFileService $sftpFileService,
        private readonly AuditLogger $auditLogger,
        private readonly AppSettingsService $appSettingsService,
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
        $this->assertDataManagerEnabled();
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($customer, $id);
        $path = trim((string) $request->query->get('path', ''));

        try {
            $listing = $this->withFileFallback(
                fn () => $this->fileService->list($instance, $path),
                fn () => $this->sftpFileService->list($instance, $path),
            );
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'root_path' => $listing['root_path'],
            'path' => $listing['path'],
            'entries' => array_map(fn (array $entry) => $this->normalizeEntry($entry), $listing['entries']),
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
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
                fn () => $this->sftpFileService->readFile($instance, $path, $name),
            );
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
            $this->withFileFallback(
                fn () => $this->fileService->writeFile($instance, $path, $name, $content),
                fn () => $this->sftpFileService->writeFile($instance, $path, $name, $content),
            );
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
            return new JsonResponse(['error' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
        $message = $exception->getMessage();

        return str_contains($message, 'File service unavailable')
            || str_contains($message, 'File service host not configured')
            || str_contains($message, 'File service TLS client certificates are not configured');
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
