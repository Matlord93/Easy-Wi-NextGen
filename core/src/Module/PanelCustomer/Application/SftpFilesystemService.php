<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\Application;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Webspace;
use App\Repository\WebspaceSftpCredentialRepository;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Psr\Log\LoggerInterface;

final class SftpFilesystemService
{
    public const int MAX_EDIT_SIZE_BYTES = 1048576;

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
        private readonly AppSettingsService $settingsService,
        private readonly WebspaceSftpCredentialRepository $credentialRepository,
        private readonly EncryptionService $encryptionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, array{name: string, path: string, type: string, size: ?int, last_modified: ?int}>
     */
    public function list(Webspace $webspace, string $path): array
    {
        $storage = $this->buildFilesystem($webspace);
        $path = $this->normalizePath($path);
        $entries = [];

        foreach ($storage->listContents($path, false) as $item) {
            $entries[] = [
                'name' => basename($item->path()),
                'path' => $item->path(),
                'type' => $item->type(),
                'size' => $item->isFile() ? $item->fileSize() : null,
                'last_modified' => $item->lastModified(),
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            if ($left['type'] !== $right['type']) {
                return $left['type'] === 'dir' ? -1 : 1;
            }

            return strcasecmp($left['name'], $right['name']);
        });

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    public function testConnection(Webspace $webspace): array
    {
        return array_map(
            static fn (array $entry): string => $entry['path'],
            $this->list($webspace, ''),
        );
    }

    public function read(Webspace $webspace, string $path): string
    {
        return $this->buildFilesystem($webspace)->read($this->normalizePath($path));
    }

    /**
     * @return resource
     */
    public function readStream(Webspace $webspace, string $path)
    {
        $stream = $this->buildFilesystem($webspace)->readStream($this->normalizePath($path));
        if (!is_resource($stream)) {
            throw new \RuntimeException('Unable to open remote stream.');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    public function writeStream(Webspace $webspace, string $path, $stream): void
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Upload stream is invalid.');
        }

        $this->buildFilesystem($webspace)->writeStream($this->normalizePath($path), $stream);
    }

    public function write(Webspace $webspace, string $path, string $content): void
    {
        $this->buildFilesystem($webspace)->write($this->normalizePath($path), $content);
    }

    public function delete(Webspace $webspace, string $path): void
    {
        $path = $this->normalizePath($path);
        $storage = $this->buildFilesystem($webspace);

        if ($storage->fileExists($path)) {
            $storage->delete($path);
            return;
        }

        if ($storage->directoryExists($path)) {
            $storage->deleteDirectory($path);
            return;
        }

        throw new \RuntimeException('Entry not found.');
    }

    public function move(Webspace $webspace, string $source, string $destination): void
    {
        $this->buildFilesystem($webspace)->move(
            $this->normalizePath($source),
            $this->normalizePath($destination),
        );
    }

    public function isEditable(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '') {
            return false;
        }

        return in_array($extension, self::ALLOWED_EDIT_EXTENSIONS, true);
    }

    public function assertEditable(string $path, int $sizeBytes): void
    {
        if (!$this->isEditable($path)) {
            throw new \RuntimeException('File type is not editable.');
        }

        if ($sizeBytes > self::MAX_EDIT_SIZE_BYTES) {
            throw new \RuntimeException('File is too large to edit (max 1 MB).');
        }
    }

    public function fileSize(Webspace $webspace, string $path): int
    {
        return $this->buildFilesystem($webspace)->fileSize($this->normalizePath($path));
    }

    public function buildChildPath(string $directory, string $name): string
    {
        $directory = $this->normalizePath($directory);
        $name = $this->normalizeName($name);

        return $directory === '' ? $name : sprintf('%s/%s', $directory, $name);
    }

    public function normalizeName(string $name): string
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

    public function normalizePath(string $path): string
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

    private function buildFilesystem(Webspace $webspace): FilesystemOperator
    {
        try {
            $credential = $this->credentialRepository->findOneByWebspace($webspace);
            if ($credential === null) {
                throw new \RuntimeException('SFTP credentials are not provisioned yet.');
            }

            $host = $this->settingsService->getSftpHost();
            if ($host === null || $host === '') {
                throw new \RuntimeException('SFTP host is not configured.');
            }

            $port = $this->settingsService->getSftpPort();
            $username = $credential->getUsername();
            $password = $this->encryptionService->decrypt($credential->getEncryptedPassword());
            $root = rtrim($webspace->getPath(), '/');

            $provider = new SftpConnectionProvider(
                $host,
                $username,
                $password,
                $port,
                false,
                10,
            );

            $adapter = new SftpAdapter($provider, $root);

            return new Filesystem($adapter);
        } catch (\Throwable $exception) {
            $this->logger->error('sftp.filesystem_build_failed', [
                'webspace_id' => $webspace->getId(),
                'exception' => $exception,
            ]);

            throw new \RuntimeException($exception->getMessage(), 0, $exception);
        }
    }
}
