<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Attribute\RequiresModule;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Entity\Webspace;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\Application\SftpFilesystemService;
use App\Module\PanelCustomer\Form\EditFileType;
use App\Module\PanelCustomer\Form\RenameFileType;
use App\Module\PanelCustomer\Form\UploadFileType;
use App\Repository\WebspaceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/files')]
#[RequiresModule('web')]
final class CustomerFileManagerController extends AbstractController
{
    private const int MAX_EDIT_SIZE_BYTES = 1_048_576;
    private const array ALLOWED_EDIT_EXTENSIONS = [
        'txt',
        'log',
        'json',
        'yml',
        'yaml',
        'md',
        'php',
        'go',
        'env',
    ];

    public function __construct(
        private readonly WebspaceRepository $webspaceRepository,
        private readonly LoggerInterface $logger,
        private readonly AppSettingsService $settingsService,
        private readonly SftpFilesystemService $sftpService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'customer_files', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->browse($request);
    }

    #[Route(path: '/browse', name: 'customer_files_browse', methods: ['GET'])]
    public function browse(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $webspaces = $this->webspaceRepository->findByCustomer($customer);
        $selectedId = (string) $request->query->get('webspace', '');
        $webspace = $this->resolveWebspace($webspaces, $selectedId);
        if ($webspace === null) {
            return $this->render('customer/files/index.html.twig', [
                'activeNav' => 'files',
                'pageTitle' => $this->translator->trans('customer_files_page_title', [], 'portal', $request->getLocale()),
                'currentPath' => '',
                'breadcrumbs' => [],
                'entries' => [],
                'listError' => 'customer_files_flash_no_webspace',
                'uploadForm' => null,
                'webspaces' => [],
                'selectedWebspace' => null,
            ]);
        }

        $pathInput = (string) $request->query->get('path', '');
        $path = '';
        $entries = [];
        $listError = null;

        try {
            $path = $this->normalizePath($pathInput);
            $entries = $this->normalizeSftpEntries($this->sftpService->list($webspace, $path));
        } catch (\Throwable $exception) {
            $listError = $exception->getMessage();
            $this->logger->error('files.list_failed', [
                'path' => $pathInput,
                'webspace_id' => $webspace->getId(),
                'root_resolution' => [
                    'path' => $webspace->getPath(),
                    'docroot' => $webspace->getDocroot(),
                ],
                'exception' => $exception,
            ]);
        }

        $uploadForm = $this->createForm(UploadFileType::class, [
            'path' => $path,
        ], [
            'action' => $this->generateUrl('customer_files_upload', [
                'webspace' => $webspace->getId(),
            ]),
        ]);

        return $this->render('customer/files/index.html.twig', [
            'activeNav' => 'files',
            'pageTitle' => $this->translator->trans('customer_files_page_title', [], 'portal', $request->getLocale()),
            'currentPath' => $path,
            'breadcrumbs' => $this->buildBreadcrumbs($path),
            'entries' => $entries,
            'listError' => $listError,
            'uploadForm' => $uploadForm->createView(),
            'webspaces' => $this->normalizeWebspaces($webspaces),
            'selectedWebspace' => $this->normalizeWebspace($webspace),
        ]);
    }

    #[Route(path: '/test-connection', name: 'customer_files_test_connection', methods: ['POST'])]
    public function testConnection(Request $request): RedirectResponse
    {
        $customer = $this->requireCustomer($request);

        $path = (string) $request->request->get('path', '');
        $webspaceId = (string) $request->request->get('webspace', '');
        $webspace = $this->findCustomerWebspace($customer, $webspaceId);

        try {
            $entries = $this->sftpService->list($webspace, '');
            $sample = array_slice(array_map(static fn (array $entry): string => $entry['name'], $entries), 0, 25);
            $this->addFlash('agent_test', [
                'status' => 'ok',
                'message' => $this->translator->trans('customer_files_server_connection_success', [], 'portal', $request->getLocale()),
                'entries' => $sample,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('files.test_connection_failed', [
                'webspace_id' => $webspace->getId(),
                'root_path' => $webspace->getPath(),
                'exception' => $exception,
            ]);
            $message = $exception->getMessage();
            $this->addFlash('agent_test', [
                'status' => 'error',
                'message' => $this->translator->trans('customer_files_server_connection_failed', ['%message%' => $message], 'portal', $request->getLocale()),
                'entries' => [],
            ]);
        }

        return $this->redirectToRoute('customer_files_browse', [
            'path' => $path,
            'webspace' => $webspace->getId(),
        ]);
    }

    #[Route(path: '/upload', name: 'customer_files_upload', methods: ['POST'])]
    public function upload(Request $request): RedirectResponse
    {
        $customer = $this->requireCustomer($request);
        $webspaceId = (string) $request->request->get('webspace', '');
        $webspace = $this->findCustomerWebspace($customer, $webspaceId);

        $form = $this->createForm(UploadFileType::class);
        $form->handleRequest($request);

        $path = '';

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'customer_files_upload_invalid');
            return $this->redirectToRoute('customer_files_browse', [
                'webspace' => $webspace->getId(),
            ]);
        }

        $path = (string) $form->get('path')->getData();
        $upload = $form->get('upload')->getData();

        if (!$upload instanceof UploadedFile) {
            $this->addFlash('error', 'customer_files_upload_no_file');
            return $this->redirectToRoute('customer_files_browse', [
                'path' => $path,
                'webspace' => $webspace->getId(),
            ]);
        }

        try {
            $this->uploadToServer($webspace, $path, $upload);
            $this->addFlash('success', 'customer_files_upload_success');
        } catch (\Throwable $exception) {
            $this->logger->error('files.upload_failed', [
                'path' => $path,
                'exception' => $exception,
            ]);
            $this->addFlash('error', $this->translator->trans('customer_files_upload_failed', ['%message%' => $exception->getMessage()], 'portal', $request->getLocale()));
        }

        return $this->redirectToRoute('customer_files_browse', [
            'path' => $path,
            'webspace' => $webspace->getId(),
        ]);
    }

    #[Route(path: '/download', name: 'customer_files_download', methods: ['GET'])]
    public function download(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $path = (string) $request->query->get('path', '');
        $webspaceId = (string) $request->query->get('webspace', '');
        $webspace = $this->findCustomerWebspace($customer, $webspaceId);

        try {
            $normalized = $this->normalizePath($path);
            if ($normalized === '') {
                throw new \RuntimeException($this->translator->trans('customer_files_missing_path', [], 'portal', $request->getLocale()));
            }
            $parent = $this->resolveParentPath($normalized);
            $name = basename($normalized);
            $content = $this->sftpService->read($webspace, $this->buildChildPath($parent, $name));
            $response = new Response($content);
            $disposition = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                basename($normalized),
            );
            $response->headers->set('Content-Disposition', $disposition);
            $response->headers->set('Content-Type', 'application/octet-stream');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        } catch (\Throwable $exception) {
            $this->logger->error('files.download_failed', [
                'path' => $path,
                'exception' => $exception,
            ]);
            $this->addFlash('error', $this->translator->trans('customer_files_download_failed_message', ['%message%' => $exception->getMessage()], 'portal', $request->getLocale()));
        }

        return $this->redirectToRoute('customer_files_browse', [
            'webspace' => $webspace->getId(),
        ]);
    }

    #[Route(path: '/delete', name: 'customer_files_delete', methods: ['POST'])]
    public function delete(Request $request): RedirectResponse
    {
        $customer = $this->requireCustomer($request);

        $path = (string) $request->request->get('path', '');
        $webspaceId = (string) $request->request->get('webspace', '');
        $webspace = $this->findCustomerWebspace($customer, $webspaceId);
        $parent = '';

        try {
            $parent = $this->resolveParentPath($path);
            if (trim($path) === '') {
                throw new \RuntimeException($this->translator->trans('customer_files_missing_path', [], 'portal', $request->getLocale()));
            }
            $normalized = $this->normalizePath($path);
            $name = basename($normalized);
            $parent = $this->resolveParentPath($normalized);
            $this->sftpService->delete($webspace, $this->buildChildPath($parent, $name));
            $this->addFlash('success', 'customer_files_delete_success');
        } catch (\Throwable $exception) {
            $this->logger->error('files.delete_failed', [
                'path' => $path,
                'exception' => $exception,
            ]);
            $this->addFlash('error', $this->translator->trans('customer_files_delete_failed', ['%message%' => $exception->getMessage()], 'portal', $request->getLocale()));
        }

        return $this->redirectToRoute('customer_files_browse', [
            'path' => $parent,
            'webspace' => $webspace->getId(),
        ]);
    }

    #[Route(path: '/rename', name: 'customer_files_rename', methods: ['GET', 'POST'])]
    public function rename(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $path = (string) $request->query->get('path', '');
        $webspaceId = (string) $request->query->get('webspace', '');
        $webspace = $this->findCustomerWebspace($customer, $webspaceId);
        $form = $this->createForm(RenameFileType::class, [
            'path' => $path,
        ], [
            'action' => $this->generateUrl('customer_files_rename', ['path' => $path, 'webspace' => $webspace->getId()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $path = (string) $form->get('path')->getData();
            $newName = (string) $form->get('newName')->getData();

            try {
                $normalized = $this->normalizePath($path);
                if ($normalized === '') {
                    throw new \RuntimeException($this->translator->trans('customer_files_missing_path', [], 'portal', $request->getLocale()));
                }
                $parent = $this->resolveParentPath($normalized);
                $name = basename($normalized);
                $this->sftpService->move($webspace, $this->buildChildPath($parent, $name), $this->buildChildPath($parent, $newName));
                $this->addFlash('success', 'customer_files_rename_success');
                return $this->redirectToRoute('customer_files_browse', [
                    'path' => $parent,
                    'webspace' => $webspace->getId(),
                ]);
            } catch (\Throwable $exception) {
                $this->logger->error('files.rename_failed', [
                    'path' => $path,
                    'exception' => $exception,
                ]);
                $this->addFlash('error', $this->translator->trans('customer_files_rename_failed', ['%message%' => $exception->getMessage()], 'portal', $request->getLocale()));
            }
        }

        return $this->render('customer/files/rename.html.twig', [
            'activeNav' => 'files',
            'pageTitle' => $this->translator->trans('customer_files_rename', [], 'portal', $request->getLocale()),
            'path' => $path,
            'form' => $form->createView(),
            'webspace' => $this->normalizeWebspace($webspace),
        ]);
    }

    #[Route(path: '/edit', name: 'customer_files_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $path = (string) $request->query->get('path', '');
        $webspaceId = (string) $request->query->get('webspace', '');
        $webspace = $this->findCustomerWebspace($customer, $webspaceId);
        $normalized = '';
        $content = '';
        $error = null;

        if ($request->isMethod('GET')) {
            try {
                if (trim($path) === '') {
                    throw new \RuntimeException($this->translator->trans('customer_files_missing_path', [], 'portal', $request->getLocale()));
                }
                $normalized = $this->normalizePath($path);
                $this->assertEditable($normalized, null, $request->getLocale());
                $parent = $this->resolveParentPath($normalized);
                $name = basename($normalized);
                $sizeBytes = $this->sftpService->fileSize($webspace, $this->buildChildPath($parent, $name));
                $this->assertEditable($normalized, $sizeBytes, $request->getLocale());
                $content = $this->sftpService->read($webspace, $this->buildChildPath($parent, $name));
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
                $this->logger->error('files.edit_load_failed', [
                    'path' => $path,
                    'exception' => $exception,
                ]);
            }
        }

        $form = $this->createForm(EditFileType::class, [
            'path' => $path,
            'content' => $content,
        ], [
            'action' => $this->generateUrl('customer_files_edit', ['path' => $path, 'webspace' => $webspace->getId()]),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $path = (string) $form->get('path')->getData();
            $content = (string) $form->get('content')->getData();

            try {
                $normalized = $this->normalizePath($path);
                $this->assertEditable($normalized, strlen($content), $request->getLocale());
                $parent = $this->resolveParentPath($normalized);
                $name = basename($normalized);
                $this->sftpService->write($webspace, $this->buildChildPath($parent, $name), $content);
                $this->addFlash('success', 'customer_files_save_success');
                return $this->redirectToRoute('customer_files_browse', [
                    'path' => $this->resolveParentPath($normalized),
                    'webspace' => $webspace->getId(),
                ]);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
                $this->logger->error('files.edit_save_failed', [
                    'path' => $path,
                    'exception' => $exception,
                ]);
            }
        }

        return $this->render('customer/files/edit.html.twig', [
            'activeNav' => 'files',
            'pageTitle' => $this->translator->trans('customer_files_edit', [], 'portal', $request->getLocale()),
            'path' => $path,
            'error' => $error,
            'form' => $form->createView(),
            'webspace' => $this->normalizeWebspace($webspace),
        ]);
    }

    #[Route(path: '/health', name: 'customer_files_health', methods: ['GET'])]
    public function health(Request $request): JsonResponse
    {
        $sftpHost = $this->settingsService->getSftpHost();
        if ($sftpHost === null || $sftpHost === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => $this->translator->trans('customer_files_sftp_host_missing', [], 'portal', $request->getLocale()),
                'missing' => ['sftp_host'],
            ]);
        }

        $webspaceId = (string) $request->query->get('webspace', '');
        if ($webspaceId === '') {
            return new JsonResponse([
                'ok' => true,
                'message' => $this->translator->trans('customer_files_sftp_config_present', [], 'portal', $request->getLocale()),
                'missing' => [],
            ]);
        }

        $webspace = $this->webspaceRepository->find($webspaceId);
        if ($webspace === null) {
            return new JsonResponse([
                'ok' => false,
                'message' => $this->translator->trans('customer_files_webspace_not_found', [], 'portal', $request->getLocale()),
                'missing' => [],
                'root_readable' => false,
            ]);
        }

        try {
            $entries = $this->sftpService->list($webspace, '');

            return new JsonResponse([
                'ok' => true,
                'message' => $this->translator->trans('customer_files_sftp_root_readable', [], 'portal', $request->getLocale()),
                'missing' => [],
                'root_readable' => true,
                'entries' => $entries,
                'webspace' => [
                    'id' => $webspace->getId(),
                    'path' => $webspace->getPath(),
                    'docroot' => $webspace->getDocroot(),
                ],
                'root_resolution' => [
                    'path' => $webspace->getPath(),
                    'docroot' => $webspace->getDocroot(),
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('files.health_sftp_failed', [
                'webspace_id' => $webspace->getId(),
                'exception' => $exception,
            ]);

            return new JsonResponse([
                'ok' => false,
                'message' => $exception->getMessage(),
                'missing' => [],
                'root_readable' => false,
                'webspace' => [
                    'id' => $webspace->getId(),
                    'path' => $webspace->getPath(),
                    'docroot' => $webspace->getDocroot(),
                ],
                'root_resolution' => [
                    'path' => $webspace->getPath(),
                    'docroot' => $webspace->getDocroot(),
                ],
            ]);
        }
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', $this->translator->trans('customer_files_error_unauthorized', [], 'portal'));
        }

        return $actor;
    }

    /**
     * @param array<int, Webspace> $webspaces
     */
    private function resolveWebspace(array $webspaces, string $selectedId): ?Webspace
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
     * @return array<int, array{id: int, domain: string, system_username: string, ftp_enabled: bool, sftp_enabled: bool, ftp_host: string, ftp_port: int, sftp_port: int, root_path: string, docroot: string}>
     */
    private function normalizeWebspaces(array $webspaces): array
    {
        return array_map(fn ($webspace) => $this->normalizeWebspace($webspace), $webspaces);
    }

    /**
     * @return array{id: int, domain: string, system_username: string, ftp_enabled: bool, sftp_enabled: bool, ftp_host: string, ftp_port: int, sftp_port: int, root_path: string, docroot: string}
     */
    private function normalizeWebspace(Webspace $webspace): array
    {
        $host = $webspace->getDomain() !== '' ? $webspace->getDomain() : ($webspace->getNode()->getName() ?? $webspace->getNode()->getId());

        return [
            'id' => $webspace->getId(),
            'domain' => $webspace->getDomain(),
            'system_username' => $webspace->getSystemUsername(),
            'ftp_enabled' => $webspace->isFtpEnabled(),
            'sftp_enabled' => $webspace->isSftpEnabled(),
            'ftp_host' => $host,
            'ftp_port' => 21,
            'sftp_port' => 22,
            'root_path' => $webspace->getPath(),
            'docroot' => $webspace->getDocroot(),
        ];
    }

    private function findCustomerWebspace(User $customer, string $webspaceId): Webspace
    {
        $webspace = $this->webspaceRepository->find($webspaceId);
        if ($webspace === null || $webspace->getCustomer()->getId() !== $customer->getId()) {
            throw $this->createNotFoundException($this->translator->trans('customer_files_webspace_not_found', [], 'portal'));
        }

        return $webspace;
    }

    /**
     * @return array<int, array{label: string, path: string}>
     */
    private function buildBreadcrumbs(string $path): array
    {
        $crumbs = [
            [
                'label' => 'Root',
                'path' => '',
            ],
        ];

        if ($path === '') {
            return $crumbs;
        }

        $segments = explode('/', $path);
        $current = '';
        foreach ($segments as $segment) {
            $current = $current === '' ? $segment : sprintf('%s/%s', $current, $segment);
            $crumbs[] = [
                'label' => $segment,
                'path' => $current,
            ];
        }

        return $crumbs;
    }

    private function resolveParentPath(string $path): string
    {
        $path = $this->normalizePath($path);
        if ($path === '') {
            return '';
        }

        $parent = dirname($path);
        if ($parent === '.' || $parent === DIRECTORY_SEPARATOR) {
            return '';
        }

        return $parent;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || $path === '/') {
            return '';
        }

        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException($this->translator->trans('customer_files_invalid_path', [], 'portal'));
        }

        if (str_starts_with($path, '/')) {
            throw new \InvalidArgumentException($this->translator->trans('customer_files_absolute_path_not_allowed', [], 'portal'));
        }

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new \InvalidArgumentException($this->translator->trans('customer_files_absolute_path_not_allowed', [], 'portal'));
        }

        $segments = array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '');
        $safe = [];

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }

            if ($segment === '..') {
                throw new \InvalidArgumentException($this->translator->trans('customer_files_path_traversal_not_allowed', [], 'portal'));
            }

            $safe[] = $segment;
        }

        return implode('/', $safe);
    }

    /**
     * @param array<int, array{name: string, path: string, type: string, size: ?int, last_modified: ?int}> $entries
     * @return array<int, array{name: string, path: string, type: string, size: ?int, last_modified: ?int, editable: bool}>
     */
    private function normalizeSftpEntries(array $entries): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $path = (string) ($entry['path'] ?? '');
            $type = (string) ($entry['type'] ?? 'file');
            if ($name === '' || $path === '') {
                continue;
            }

            $normalizedPath = $this->normalizePath($path);
            $normalizedType = $type === 'dir' ? 'dir' : 'file';
            $normalized[] = [
                'name' => $name,
                'path' => $normalizedPath,
                'type' => $normalizedType,
                'size' => $normalizedType === 'file' ? (int) ($entry['size'] ?? 0) : null,
                'last_modified' => $this->parseModifiedAt($entry['last_modified'] ?? null),
                'editable' => $normalizedType === 'file' && $this->isEditable($normalizedPath),
            ];
        }

        return $normalized;
    }

    private function uploadToServer(Webspace $webspace, string $path, UploadedFile $upload): void
    {
        $targetPath = $this->buildChildPath($path, $upload->getClientOriginalName());
        $stream = fopen($upload->getPathname(), 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException($this->translator->trans('customer_files_upload_stream_failed', [], 'portal'));
        }

        try {
            $this->sftpService->writeStream($webspace, $targetPath, $stream);
        } finally {
            fclose($stream);
        }
    }

    private function buildChildPath(string $directory, string $name): string
    {
        $directory = $this->normalizePath($directory);
        $name = $this->normalizeName($name);

        return $directory === '' ? $name : sprintf('%s/%s', $directory, $name);
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException($this->translator->trans('customer_files_invalid_name', [], 'portal'));
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException($this->translator->trans('customer_files_invalid_name', [], 'portal'));
        }

        return $name;
    }

    private function parseModifiedAt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    private function isEditable(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return false;
        }

        return in_array($extension, self::ALLOWED_EDIT_EXTENSIONS, true);
    }

    private function assertEditable(string $path, ?int $sizeBytes, ?string $locale = null): void
    {
        if (!$this->isEditable($path)) {
            throw new \RuntimeException($this->translator->trans('customer_files_file_type_not_editable', [], 'portal', $locale));
        }

        if ($sizeBytes !== null && $sizeBytes > self::MAX_EDIT_SIZE_BYTES) {
            throw new \RuntimeException($this->translator->trans('customer_files_file_too_large', ['%max%' => '1 MB'], 'portal', $locale));
        }
    }
}
