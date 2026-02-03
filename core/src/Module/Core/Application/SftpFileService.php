<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Application\Exception\SftpException;
use App\Module\Core\Domain\Entity\Instance;
use App\Repository\InstanceSftpCredentialRepository;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

final class SftpFileService
{
    public const int EDITOR_MAX_BYTES = 1048576;

    public function __construct(
        private readonly InstanceFilesystemResolver $filesystemResolver,
        private readonly AppSettingsService $settingsService,
        private readonly InstanceSftpCredentialRepository $instanceSftpCredentialRepository,
        private readonly EncryptionService $encryptionService,
    ) {
    }

    /**
     * @return array{root_path: string, path: string, entries: array<int, array{name: string, size: int, mode: string, modified_at: string, is_dir: bool}>}
     */
    public function list(Instance $instance, string $path): array
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        $rootPath = $this->filesystemResolver->resolveInstanceDir($instance);

        $sftp = $this->connect($instance);
        $remoteRoot = $this->resolveRemoteRoot($sftp, $rootPath);
        $remotePath = $this->joinRemotePath($remoteRoot, $normalizedPath);
        if (!$sftp->is_dir($remotePath)) {
            throw new SftpException('sftp_path_not_found', 'Directory not found.', 404, [
                'path' => $normalizedPath,
            ]);
        }

        $listing = $sftp->rawlist($remotePath, true);
        if ($listing === false) {
            throw new SftpException('sftp_list_failed', 'Unable to list directory.', 502, [
                'path' => $normalizedPath,
            ]);
        }

        $entries = [];
        foreach ($listing as $name => $info) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $mode = is_array($info) ? ($info['mode'] ?? null) : null;
            $mtime = is_array($info) ? ($info['mtime'] ?? null) : null;
            $size = is_array($info) ? ($info['size'] ?? 0) : 0;
            $type = is_array($info) ? ($info['type'] ?? null) : null;
            $isDir = $type === SFTP::TYPE_DIRECTORY;

            $entries[] = [
                'name' => (string) $name,
                'size' => is_int($size) ? $size : 0,
                'mode' => $mode !== null ? sprintf('%04o', ((int) $mode) & 0777) : '',
                'modified_at' => $mtime !== null ? date('Y-m-d H:i:s', (int) $mtime) : '',
                'is_dir' => $isDir,
            ];
        }

        return [
            'root_path' => $rootPath,
            'path' => $normalizedPath,
            'entries' => $entries,
        ];
    }

    public function readFile(Instance $instance, string $path, string $name, ?int $maxBytes = null): string
    {
        $sftp = $this->connect($instance);
        $remotePath = $this->resolveFilePath($instance, $sftp, $path, $name);
        if ($maxBytes !== null && $maxBytes > 0) {
            $size = $sftp->filesize($remotePath);
            if (is_int($size) && $size > $maxBytes) {
                throw new SftpException('sftp_file_too_large', 'File is too large to edit (max 1 MB).', 413, [
                    'size_bytes' => $size,
                    'max_bytes' => $maxBytes,
                ]);
            }
        }
        $content = $sftp->get($remotePath);
        if ($content === false) {
            throw new SftpException('sftp_read_failed', 'Failed to download file.', 502, [
                'path' => $remotePath,
            ]);
        }
        if ($maxBytes !== null && $maxBytes > 0 && strlen($content) > $maxBytes) {
            throw new SftpException('sftp_file_too_large', 'File is too large to edit (max 1 MB).', 413, [
                'size_bytes' => strlen($content),
                'max_bytes' => $maxBytes,
            ]);
        }

        return $content;
    }

    public function readFileForEditor(Instance $instance, string $path, string $name): string
    {
        return $this->readFile($instance, $path, $name, self::EDITOR_MAX_BYTES);
    }

    public function writeFile(Instance $instance, string $path, string $name, string $content): void
    {
        $sftp = $this->connect($instance);
        $remotePath = $this->resolveFilePath($instance, $sftp, $path, $name);
        if (!$sftp->put($remotePath, $content, SFTP::SOURCE_STRING)) {
            throw new SftpException('sftp_write_failed', 'Failed to save file.', 502, [
                'path' => $remotePath,
            ]);
        }
    }

    public function makeDirectory(Instance $instance, string $path, string $name): void
    {
        $sftp = $this->connect($instance);
        $remotePath = $this->resolveFilePath($instance, $sftp, $path, $name);
        if (!$sftp->mkdir($remotePath, -1, true)) {
            throw new SftpException('sftp_mkdir_failed', 'Failed to create directory.', 502, [
                'path' => $remotePath,
            ]);
        }
    }

    public function delete(Instance $instance, string $path, string $name): void
    {
        $sftp = $this->connect($instance);
        $remotePath = $this->resolveFilePath($instance, $sftp, $path, $name);
        if (!$sftp->delete($remotePath, true)) {
            throw new SftpException('sftp_delete_failed', 'Failed to delete entry.', 502, [
                'path' => $remotePath,
            ]);
        }
    }

    public function rename(Instance $instance, string $path, string $name, string $newName): void
    {
        $sftp = $this->connect($instance);
        $remotePath = $this->resolveFilePath($instance, $sftp, $path, $name);
        $newPath = $this->resolveFilePath($instance, $sftp, $path, $newName);
        if (!$sftp->rename($remotePath, $newPath)) {
            throw new SftpException('sftp_rename_failed', 'Failed to rename entry.', 502, [
                'path' => $remotePath,
                'new_path' => $newPath,
            ]);
        }
    }

    public function chmod(Instance $instance, string $path, string $name, int $mode): void
    {
        $sftp = $this->connect($instance);
        $remotePath = $this->resolveFilePath($instance, $sftp, $path, $name);
        if (!$sftp->chmod($mode, $remotePath, true)) {
            throw new SftpException('sftp_chmod_failed', 'Failed to update permissions.', 502, [
                'path' => $remotePath,
                'mode' => $mode,
            ]);
        }
    }

    public function extract(Instance $instance, string $path, string $name, string $destination): void
    {
        $normalizedDestination = $this->normalizeRelativePath($destination);
        $rootPath = $this->filesystemResolver->resolveInstanceDir($instance);

        $sftp = $this->connect($instance);
        $remoteRoot = $this->resolveRemoteRoot($sftp, $rootPath);
        $remoteArchivePath = $this->resolveFilePath($instance, $sftp, $path, $name, $remoteRoot);
        $destinationPath = $this->joinRemotePath($remoteRoot, $normalizedDestination);
        $content = $sftp->get($remoteArchivePath);
        if ($content === false) {
            throw new SftpException('sftp_read_failed', 'Failed to download archive.', 502, [
                'path' => $remoteArchivePath,
            ]);
        }

        $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'easywi_extract_' . bin2hex(random_bytes(6));
        $archivePath = $workDir . DIRECTORY_SEPARATOR . $name;
        $extractPath = $workDir . DIRECTORY_SEPARATOR . 'extract';

        if (!mkdir($extractPath, 0755, true) && !is_dir($extractPath)) {
            throw new SftpException('sftp_extract_failed', 'Failed to prepare extraction directory.', 500);
        }

        if (file_put_contents($archivePath, $content) === false) {
            $this->cleanupWorkDir($workDir);
            throw new SftpException('sftp_extract_failed', 'Failed to store archive.', 500);
        }

        try {
            $lowerName = strtolower($name);
            if (str_ends_with($lowerName, '.zip')) {
                $zip = new \ZipArchive();
                if ($zip->open($archivePath) !== true) {
                    throw new SftpException('sftp_extract_failed', 'Failed to open zip archive.', 500);
                }
                $zip->extractTo($extractPath);
                $zip->close();
            } elseif (str_ends_with($lowerName, '.tar') || str_ends_with($lowerName, '.tar.gz') || str_ends_with($lowerName, '.tgz')) {
                $phar = new \PharData($archivePath);
                if (str_ends_with($lowerName, '.tar.gz') || str_ends_with($lowerName, '.tgz')) {
                    $phar->decompress();
                    $archivePath = preg_replace('/\.(tgz|tar\.gz)$/', '.tar', $archivePath);
                    $phar = new \PharData($archivePath);
                }
                $phar->extractTo($extractPath, null, true);
            } else {
                throw new SftpException('sftp_extract_failed', 'Unsupported archive format.', 422);
            }

            $this->uploadExtracted($sftp, $extractPath, $destinationPath);
        } finally {
            $this->cleanupWorkDir($workDir);
        }
    }

    private function connect(Instance $instance): SFTP
    {
        $host = $this->resolveHost($instance);
        $port = $this->resolvePort($instance);
        $username = $this->resolveUsername($instance);
        $credential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
        $auth = $credential === null ? $this->resolveAuthentication() : null;

        $sftp = new SFTP($host, $port, 10);
        if ($auth !== null) {
            $loggedIn = $sftp->login($username, $auth);
        } else {
            $loggedIn = $sftp->login($username, $this->resolvePassword($instance));
        }

        if (!$loggedIn) {
            throw new SftpException('sftp_auth_failed', 'Unable to authenticate with SFTP server.', 502, [
                'host' => $host,
                'port' => $port,
                'username' => $username,
            ]);
        }

        return $sftp;
    }

    private function resolveHost(Instance $instance): string
    {
        $metadata = $instance->getNode()->getMetadata();
        $host = is_array($metadata) ? ($metadata['sftp_host'] ?? null) : null;
        if (is_string($host) && $host !== '') {
            return $host;
        }

        $lastIp = $instance->getNode()->getLastHeartbeatIp();
        if ($lastIp !== null && $lastIp !== '') {
            return $lastIp;
        }

        $host = $this->settingsService->getSftpHost();
        if (is_string($host) && $host !== '') {
            return $host;
        }

        throw new SftpException('sftp_misconfigured', 'SFTP host not configured.', 422);
    }

    private function resolvePort(Instance $instance): int
    {
        $metadata = $instance->getNode()->getMetadata();
        $port = is_array($metadata) ? ($metadata['sftp_port'] ?? null) : null;
        if (is_numeric($port)) {
            return max(1, (int) $port);
        }

        return $this->settingsService->getSftpPort();
    }

    private function resolveUsername(Instance $instance): string
    {
        $credential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
        if ($credential !== null) {
            return $credential->getUsername();
        }

        $metadata = $instance->getNode()->getMetadata();
        $username = is_array($metadata) ? ($metadata['sftp_username'] ?? null) : null;
        if (is_string($username) && $username !== '') {
            return $username;
        }

        $username = $this->settingsService->getSftpUsername();
        if (is_string($username) && $username !== '') {
            return $username;
        }

        return basename($this->filesystemResolver->resolveInstanceDir($instance));
    }

    private function resolvePassword(Instance $instance): string
    {
        $credential = $this->instanceSftpCredentialRepository->findOneByInstance($instance);
        if ($credential !== null) {
            return $this->encryptionService->decrypt($credential->getEncryptedPassword());
        }

        $password = $this->settingsService->getSftpPassword();
        if (is_string($password) && $password !== '') {
            return $password;
        }

        throw new SftpException('sftp_misconfigured', 'SFTP password not configured.', 422);
    }

    private function resolveAuthentication(): ?\phpseclib3\Crypt\PublicKey
    {
        $key = $this->settingsService->getSftpPrivateKey();
        $path = $this->settingsService->getSftpPrivateKeyPath();
        if ((!is_string($key) || $key === '') && is_string($path) && $path !== '' && is_file($path)) {
            $key = (string) file_get_contents($path);
        }

        if (!is_string($key) || $key === '') {
            return null;
        }

        $passphrase = $this->settingsService->getSftpPrivateKeyPassphrase();
        if (is_string($passphrase) && $passphrase !== '') {
            return PublicKeyLoader::load($key, $passphrase);
        }

        return PublicKeyLoader::load($key);
    }

    private function resolveFilePath(Instance $instance, SFTP $sftp, string $path, string $name, ?string $remoteRoot = null): string
    {
        $this->assertValidName($name);
        $normalizedPath = $this->normalizeRelativePath($path);
        $relative = $normalizedPath === '' ? $name : $normalizedPath . '/' . $name;
        $rootPath = $this->filesystemResolver->resolveInstanceDir($instance);
        $remoteRoot = $remoteRoot ?? $this->resolveRemoteRoot($sftp, $rootPath);

        return $this->joinRemotePath($remoteRoot, $relative);
    }

    private function joinRemotePath(string $rootPath, string $relative): string
    {
        $relative = ltrim($relative, '/');
        if ($rootPath === '' || $rootPath === '/') {
            return $relative === '' ? '/' : '/' . $relative;
        }

        $rootPath = rtrim($rootPath, '/');
        return $relative === '' ? $rootPath : $rootPath . '/' . $relative;
    }

    private function resolveRemoteRoot(SFTP $sftp, string $rootPath): string
    {
        $rootPath = rtrim($rootPath, '/');
        if ($rootPath !== '' && $sftp->is_dir($rootPath)) {
            return $rootPath;
        }

        return '';
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $safe = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new SftpException('sftp_path_invalid', 'Path traversal is not allowed.', 400, [
                    'path' => $path,
                ]);
            }
            $safe[] = $segment;
        }

        return implode('/', $safe);
    }

    private function assertValidName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \RuntimeException('Invalid name.');
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new \RuntimeException('Invalid name.');
        }
    }

    private function uploadExtracted(SFTP $sftp, string $sourcePath, string $destinationPath): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }

            $relativePath = substr($item->getPathname(), strlen($sourcePath) + 1);
            $relativePath = $this->normalizeRelativePath($relativePath);
            if ($relativePath === '') {
                continue;
            }

            $targetPath = $this->joinRemotePath($destinationPath, $relativePath);

            if ($item->isDir()) {
                $sftp->mkdir($targetPath, -1, true);
                continue;
            }

            $directory = dirname($targetPath);
            if ($directory !== '' && !$sftp->is_dir($directory)) {
                $sftp->mkdir($directory, -1, true);
            }

            $content = file_get_contents($item->getPathname());
            if ($content === false || !$sftp->put($targetPath, $content, SFTP::SOURCE_STRING)) {
                throw new SftpException('sftp_extract_failed', 'Failed to upload extracted file.', 502, [
                    'path' => $targetPath,
                ]);
            }
        }
    }

    private function cleanupWorkDir(string $workDir): void
    {
        if (!is_dir($workDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($workDir);
    }
}
