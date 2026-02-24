<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\Exception\FileServiceException;
use App\Module\Core\Application\WebspaceFileServiceClient;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\Form\EditFileType;
use App\Module\PanelCustomer\Form\RenameFileType;
use App\Module\PanelCustomer\Form\UploadFileType;
use App\Repository\WebspaceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/files')]
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
        private readonly WebspaceFileServiceClient $fileService,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly LoggerInterface $logger,
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
                'pageTitle' => 'Dateimanager',
                'currentPath' => '',
                'breadcrumbs' => [],
                'entries' => [],
                'listError' => 'Kein Webspace verfügbar.',
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
            $listing = $this->fileService->list($webspace, $path);
            $entries = $this->normalizeEntries($listing['entries'], $path);
        } catch (\Throwable $exception) {
            if ($exception instanceof FileServiceException) {
                if ($exception->getErrorCode() === 'AGENT_BASEDIR_MISMATCH') {
                    $details = $exception->getDetails();
                    $candidateRoot = (string) ($details['candidate_root'] ?? '');
                    $agentRoot = (string) ($details['agent_root'] ?? '');
                    $suggestedBaseDir = $this->suggestedAgentBaseDir($candidateRoot);
                    $listError = sprintf(
                        'Agent-Konfiguration erforderlich: Webspace-Pfad "%s" liegt außerhalb von "%s". Setze auf dem Node in /etc/easywi/agent.conf: file_base_dir=%s (oder ein gemeinsames Parent-Verzeichnis), danach Agent neu starten (systemctl restart easywi-agent) und Dateirechte prüfen. (%s)',
                        $candidateRoot,
                        $agentRoot,
                        $suggestedBaseDir,
                        $exception->getErrorCode(),
                    );
                } else {
                    $listError = sprintf('%s (%s)', $exception->getMessage(), $exception->getErrorCode());
                }
            } else {
                $listError = $exception->getMessage();
            }
            $this->logger->error('files.list_failed', [
                'path' => $pathInput,
                'webspace_id' => $webspace->getId(),
                'root_resolution' => $this->fileService->debugRootResolution($webspace),
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
            'pageTitle' => 'Dateimanager',
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
            $entries = $this->fileService->list($webspace, '');
            $sample = array_slice(array_map(static fn (array $entry): string => $entry['name'], $entries['entries']), 0, 25);
            $this->addFlash('agent_test', [
                'status' => 'ok',
                'message' => 'Agent-Verbindung erfolgreich. Root kann gelesen werden.',
                'entries' => $sample,
            ]);
        } catch (\Throwable $exception) {
            $rootResolution = $this->fileService->debugRootResolution($webspace);
            $this->logger->error('files.test_connection_failed', [
                'webspace_id' => $webspace->getId(),
                'root_resolution' => $rootResolution,
                'exception' => $exception,
            ]);
            $message = $exception->getMessage();
            if ($exception instanceof FileServiceException) {
                if ($exception->getErrorCode() === 'AGENT_BASEDIR_MISMATCH') {
                    $details = $exception->getDetails();
                    $candidateRoot = (string) ($details['candidate_root'] ?? '');
                    $agentRoot = (string) ($details['agent_root'] ?? '');
                    $suggestedBaseDir = $this->suggestedAgentBaseDir($candidateRoot);
                    $message = sprintf(
                        'Agent-Konfiguration erforderlich (Webspace: "%s", Agent: "%s"). In /etc/easywi/agent.conf file_base_dir auf %s (oder Parent) setzen, Agent neu starten und Rechte prüfen. (%s)',
                        $candidateRoot,
                        $agentRoot,
                        $suggestedBaseDir,
                        $exception->getErrorCode(),
                    );
                } else {
                    $message = sprintf('%s (%s)', $exception->getMessage(), $exception->getErrorCode());
                }
            }
            $this->addFlash('agent_test', [
                'status' => 'error',
                'message' => sprintf('Agent-Verbindung fehlgeschlagen: %s', $message),
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
            $this->addFlash('error', 'Upload fehlgeschlagen: ungültige Eingabe.');
            return $this->redirectToRoute('customer_files_browse', [
                'webspace' => $webspace->getId(),
            ]);
        }

        $path = (string) $form->get('path')->getData();
        $upload = $form->get('upload')->getData();

        if (!$upload instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $this->addFlash('error', 'Upload fehlgeschlagen: keine Datei gefunden.');
            return $this->redirectToRoute('customer_files_browse', [
                'path' => $path,
                'webspace' => $webspace->getId(),
            ]);
        }

        try {
            $this->fileService->uploadFile($webspace, $path, $upload);
            $this->addFlash('success', 'Upload erfolgreich.');
        } catch (\Throwable $exception) {
            $this->logger->error('files.upload_failed', [
                'path' => $path,
                'exception' => $exception,
            ]);
            $this->addFlash('error', sprintf('Upload fehlgeschlagen: %s', $exception->getMessage()));
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
                throw new \RuntimeException('Dateipfad fehlt.');
            }
            $parent = $this->resolveParentPath($normalized);
            $name = basename($normalized);
            $content = $this->fileService->downloadFile($webspace, $parent, $name);
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
            $this->addFlash('error', sprintf('Download fehlgeschlagen: %s', $exception->getMessage()));
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
                throw new \RuntimeException('Pfad fehlt.');
            }
            $normalized = $this->normalizePath($path);
            $name = basename($normalized);
            $parent = $this->resolveParentPath($normalized);
            $this->fileService->delete($webspace, $parent, $name);
            $this->addFlash('success', 'Eintrag wurde gelöscht.');
        } catch (\Throwable $exception) {
            $this->logger->error('files.delete_failed', [
                'path' => $path,
                'exception' => $exception,
            ]);
            $this->addFlash('error', sprintf('Löschen fehlgeschlagen: %s', $exception->getMessage()));
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
                    throw new \RuntimeException('Pfad fehlt.');
                }
                $parent = $this->resolveParentPath($normalized);
                $name = basename($normalized);
                $this->fileService->rename($webspace, $parent, $name, $newName);
                $this->addFlash('success', 'Eintrag wurde umbenannt.');
                return $this->redirectToRoute('customer_files_browse', [
                    'path' => $parent,
                    'webspace' => $webspace->getId(),
                ]);
            } catch (\Throwable $exception) {
                $this->logger->error('files.rename_failed', [
                    'path' => $path,
                    'exception' => $exception,
                ]);
                $this->addFlash('error', sprintf('Umbenennen fehlgeschlagen: %s', $exception->getMessage()));
            }
        }

        return $this->render('customer/files/rename.html.twig', [
            'activeNav' => 'files',
            'pageTitle' => 'Datei umbenennen',
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
                    throw new \RuntimeException('Dateipfad fehlt.');
                }
                $normalized = $this->normalizePath($path);
                $this->assertEditable($normalized, null);
                $parent = $this->resolveParentPath($normalized);
                $name = basename($normalized);
                $content = $this->fileService->readFileForEditor($webspace, $parent, $name);
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
                $this->assertEditable($normalized, strlen($content));
                $parent = $this->resolveParentPath($normalized);
                $name = basename($normalized);
                $this->fileService->writeFile($webspace, $parent, $name, $content);
                $this->addFlash('success', 'Datei gespeichert.');
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
            'pageTitle' => 'Datei bearbeiten',
            'path' => $path,
            'error' => $error,
            'form' => $form->createView(),
            'webspace' => $this->normalizeWebspace($webspace),
        ]);
    }

    #[Route(path: '/health', name: 'customer_files_health', methods: ['GET'])]
    public function health(Request $request): JsonResponse
    {
        $webspaceId = (string) $request->query->get('webspace', '');
        if ($webspaceId === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Missing required webspace parameter.',
                'missing' => ['webspace'],
                'root_readable' => false,
            ]);
        }

        $webspace = $this->webspaceRepository->find($webspaceId);
        if ($webspace === null) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Webspace not found.',
                'missing' => [],
                'root_readable' => false,
            ]);
        }

        try {
            $entries = $this->fileService->list($webspace, '');
            return new JsonResponse([
                'ok' => true,
                'message' => 'Agent reachable and root readable.',
                'missing' => [],
                'root_readable' => true,
                'entries' => $entries,
                'webspace' => [
                    'id' => $webspace->getId(),
                    'path' => $webspace->getPath(),
                    'docroot' => $webspace->getDocroot(),
                ],
                'root_resolution' => $this->fileService->debugRootResolution($webspace),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('files.health_failed', [
                'webspace_id' => $webspace->getId(),
                'exception' => $exception,
            ]);

            $errorCode = $exception instanceof FileServiceException ? $exception->getErrorCode() : null;
            $message = $exception->getMessage();
            if ($errorCode === 'AGENT_BASEDIR_MISMATCH') {
                $details = $exception instanceof FileServiceException ? $exception->getDetails() : [];
                $candidateRoot = (string) ($details['candidate_root'] ?? '');
                $agentRoot = (string) ($details['agent_root'] ?? '');
                $suggestedBaseDir = $this->suggestedAgentBaseDir($candidateRoot);
                $message = sprintf(
                    'Agent file_base_dir mismatch: webspace root "%s" outside "%s". Set file_base_dir in /etc/easywi/agent.conf to include webspaces (e.g. %s), restart easywi-agent, then retry. (%s)',
                    $candidateRoot,
                    $agentRoot,
                    $suggestedBaseDir,
                    $errorCode,
                );
            } elseif ($errorCode !== null) {
                $message = sprintf('%s (%s)', $message, $errorCode);
            }

            return new JsonResponse([
                'ok' => false,
                'message' => sprintf('Agent check failed: %s', $message),
                'missing' => [],
                'root_readable' => false,
                'error_code' => $errorCode,
                'webspace' => [
                    'id' => $webspace->getId(),
                    'path' => $webspace->getPath(),
                    'docroot' => $webspace->getDocroot(),
                ],
                'root_resolution' => $this->fileService->debugRootResolution($webspace),
            ]);
        }
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
     * @param array<int, \App\Module\Core\Domain\Entity\Webspace> $webspaces
     */
    private function resolveWebspace(array $webspaces, string $selectedId): ?\App\Module\Core\Domain\Entity\Webspace
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
    private function normalizeWebspace(\App\Module\Core\Domain\Entity\Webspace $webspace): array
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

    private function findCustomerWebspace(User $customer, string $webspaceId): \App\Module\Core\Domain\Entity\Webspace
    {
        $webspace = $this->webspaceRepository->find($webspaceId);
        if ($webspace === null || $webspace->getCustomer()->getId() !== $customer->getId()) {
            throw $this->createNotFoundException('Webspace not found.');
        }

        return $webspace;
    }

    private function suggestedAgentBaseDir(string $candidateRoot): string
    {
        if ($candidateRoot === '' || $candidateRoot === '/') {
            return '/var/www';
        }

        $normalized = rtrim($candidateRoot, '/');
        $parent = dirname($normalized);

        if ($parent === '' || $parent === '.' || $parent === '/') {
            return $normalized;
        }

        return $parent;
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
            throw new \InvalidArgumentException('Invalid path.');
        }

        if (str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Absolute paths are not allowed.');
        }

        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new \InvalidArgumentException('Absolute paths are not allowed.');
        }

        $segments = array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '');
        $safe = [];

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }

            if ($segment === '..') {
                throw new \InvalidArgumentException('Path traversal is not allowed.');
            }

            $safe[] = $segment;
        }

        return implode('/', $safe);
    }

    private function normalizeEntries(array $entries, string $path): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $entryPath = $this->buildChildPath($path, $name);
            $type = !empty($entry['is_dir']) ? 'dir' : 'file';
            $normalized[] = [
                'name' => $name,
                'path' => $entryPath,
                'type' => $type,
                'size' => $type === 'file' ? (int) ($entry['size'] ?? 0) : null,
                'last_modified' => $this->parseModifiedAt($entry['modified_at'] ?? null),
                'editable' => $type === 'file' && $this->isEditable($entryPath),
            ];
        }

        return $normalized;
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
            throw new \InvalidArgumentException('Invalid name.');
        }

        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Invalid name.');
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

    private function assertEditable(string $path, ?int $sizeBytes): void
    {
        if (!$this->isEditable($path)) {
            throw new \RuntimeException('File type is not editable.');
        }

        if ($sizeBytes !== null && $sizeBytes > self::MAX_EDIT_SIZE_BYTES) {
            throw new \RuntimeException('File is too large to edit (max 1 MB).');
        }
    }
}
