<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\WebspaceSftpProvisioner;
use App\Repository\WebspaceRepository;
use App\Repository\WebspaceSftpCredentialRepository;
use App\Module\PanelCustomer\Application\SftpFilesystemService;
use App\Module\PanelCustomer\Form\EditFileType;
use App\Module\PanelCustomer\Form\RenameFileType;
use App\Module\PanelCustomer\Form\UploadFileType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/files')]
final class CustomerFileManagerController extends AbstractController
{
    public function __construct(
        private readonly SftpFilesystemService $filesystem,
        private readonly WebspaceRepository $webspaceRepository,
        private readonly WebspaceSftpCredentialRepository $sftpCredentialRepository,
        private readonly WebspaceSftpProvisioner $sftpProvisioner,
        private readonly AppSettingsService $settingsService,
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
            $this->sftpProvisioner->ensureCredential($customer, $webspace);
            $path = $this->filesystem->normalizePath($pathInput);
            $entries = $this->filesystem->list($webspace, $path);
            $entries = array_map(function (array $entry): array {
                $entry['editable'] = $entry['type'] === 'file' && $this->filesystem->isEditable($entry['path']);
                return $entry;
            }, $entries);
        } catch (\Throwable $exception) {
            $listError = $exception->getMessage();
            $this->logger->error('files.list_failed', [
                'path' => $pathInput,
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
            $this->sftpProvisioner->ensureCredential($customer, $webspace);
            $entries = $this->filesystem->testConnection($webspace);
            $this->addFlash('sftp_test', [
                'status' => 'ok',
                'message' => 'SFTP-Verbindung erfolgreich. Root kann gelesen werden.',
                'entries' => array_slice($entries, 0, 25),
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('files.test_connection_failed', [
                'exception' => $exception,
            ]);
            $this->addFlash('sftp_test', [
                'status' => 'error',
                'message' => sprintf('SFTP-Verbindung fehlgeschlagen: %s', $exception->getMessage()),
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
            $this->sftpProvisioner->ensureCredential($customer, $webspace);
            $targetPath = $this->filesystem->buildChildPath($path, $upload->getClientOriginalName());
            $stream = fopen($upload->getPathname(), 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Upload-Stream konnte nicht geöffnet werden.');
            }
            try {
                $this->filesystem->writeStream($webspace, $targetPath, $stream);
            } finally {
                fclose($stream);
            }
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
            $this->sftpProvisioner->ensureCredential($customer, $webspace);
            $normalized = $this->filesystem->normalizePath($path);
            if ($normalized === '') {
                throw new \RuntimeException('Dateipfad fehlt.');
            }
            $stream = $this->filesystem->readStream($webspace, $normalized);
            $response = new StreamedResponse(static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            });
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
            $this->sftpProvisioner->ensureCredential($customer, $webspace);
            $this->filesystem->delete($webspace, $path);
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
                $normalized = $this->filesystem->normalizePath($path);
                if ($normalized === '') {
                    throw new \RuntimeException('Pfad fehlt.');
                }
                $parent = $this->resolveParentPath($normalized);
                $newPath = $this->filesystem->buildChildPath($parent, $newName);
                $this->sftpProvisioner->ensureCredential($customer, $webspace);
                $this->filesystem->move($webspace, $normalized, $newPath);
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
                $normalized = $this->filesystem->normalizePath($path);
                $this->sftpProvisioner->ensureCredential($customer, $webspace);
                $size = $this->filesystem->fileSize($webspace, $normalized);
                $this->filesystem->assertEditable($normalized, $size);
                $content = $this->filesystem->read($webspace, $normalized);
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
                $normalized = $this->filesystem->normalizePath($path);
                $this->filesystem->assertEditable($normalized, strlen($content));
                $this->sftpProvisioner->ensureCredential($customer, $webspace);
                $this->filesystem->write($webspace, $normalized, $content);
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
        $host = $this->settingsService->getSftpHost();
        $port = $this->settingsService->getSftpPort();
        $settings = $this->settingsService->getSettings();

        if ($host === null || $host === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => 'SFTP host is not configured.',
                'missing' => ['sftp_host'],
                'sftp_reachable' => false,
                'root_readable' => false,
                'config' => [
                    'host' => null,
                    'port' => $port,
                    'host_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_HOST, $settings),
                    'port_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_PORT, $settings),
                ],
            ]);
        }

        $webspaceId = (string) $request->query->get('webspace', '');
        if ($webspaceId === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Missing required webspace parameter.',
                'missing' => ['webspace'],
                'sftp_reachable' => false,
                'root_readable' => false,
            ]);
        }

        $webspace = $this->webspaceRepository->find($webspaceId);
        if ($webspace === null) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Webspace not found.',
                'missing' => [],
                'sftp_reachable' => false,
                'root_readable' => false,
                'config' => [
                    'host' => $host,
                    'port' => $port,
                    'host_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_HOST, $settings),
                    'port_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_PORT, $settings),
                ],
            ]);
        }

        $credential = $this->sftpCredentialRepository->findOneByWebspace($webspace);
        if ($credential === null) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'SFTP credentials are not provisioned yet.',
                'missing' => ['webspace_sftp_credentials'],
                'sftp_reachable' => false,
                'root_readable' => false,
                'config' => [
                    'host' => $host,
                    'port' => $port,
                    'host_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_HOST, $settings),
                    'port_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_PORT, $settings),
                ],
                'webspace' => [
                    'id' => $webspace->getId(),
                    'path' => $webspace->getPath(),
                ],
            ]);
        }

        try {
            $entries = $this->filesystem->testConnection($webspace);
            return new JsonResponse([
                'ok' => true,
                'message' => 'SFTP reachable and root readable.',
                'missing' => [],
                'sftp_reachable' => true,
                'root_readable' => true,
                'entries' => $entries,
                'config' => [
                    'host' => $host,
                    'port' => $port,
                    'host_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_HOST, $settings),
                    'port_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_PORT, $settings),
                ],
                'webspace' => [
                    'id' => $webspace->getId(),
                    'path' => $webspace->getPath(),
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('files.health_failed', [
                'webspace_id' => $webspace->getId(),
                'host' => $host,
                'port' => $port,
                'exception' => $exception,
            ]);

            return new JsonResponse([
                'ok' => false,
                'message' => sprintf('SFTP check failed: %s', $exception->getMessage()),
                'missing' => [],
                'sftp_reachable' => false,
                'root_readable' => false,
                'config' => [
                    'host' => $host,
                    'port' => $port,
                    'host_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_HOST, $settings),
                    'port_source' => $this->resolveSettingSource(AppSettingsService::KEY_SFTP_PORT, $settings),
                ],
                'webspace' => [
                    'id' => $webspace->getId(),
                    'path' => $webspace->getPath(),
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveSettingSource(string $key, array $settings): string
    {
        $value = $settings[$key] ?? null;
        if ($value !== null && $value !== '') {
            return 'settings';
        }

        return 'default';
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
     * @return array<int, array{id: int, domain: string, system_username: string}>
     */
    private function normalizeWebspaces(array $webspaces): array
    {
        return array_map(fn ($webspace) => $this->normalizeWebspace($webspace), $webspaces);
    }

    /**
     * @return array{id: int, domain: string, system_username: string}
     */
    private function normalizeWebspace(\App\Module\Core\Domain\Entity\Webspace $webspace): array
    {
        return [
            'id' => $webspace->getId(),
            'domain' => $webspace->getDomain(),
            'system_username' => $webspace->getSystemUsername(),
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
        $path = $this->filesystem->normalizePath($path);
        if ($path === '') {
            return '';
        }

        $parent = dirname($path);
        if ($parent === '.' || $parent === DIRECTORY_SEPARATOR) {
            return '';
        }

        return $parent;
    }
}
