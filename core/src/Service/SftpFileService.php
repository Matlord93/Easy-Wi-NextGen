<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Instance;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

final class SftpFileService
{
    public function __construct(
        private readonly InstanceFilesystemResolver $filesystemResolver,
        private readonly AppSettingsService $settingsService,
    ) {
    }

    /**
     * @return array{root_path: string, path: string, entries: array<int, array{name: string, size: int, mode: string, modified_at: string, is_dir: bool}>}
     */
    public function list(Instance $instance, string $path): array
    {
        $normalizedPath = $this->normalizeRelativePath($path);
        $rootPath = $this->filesystemResolver->resolveInstanceDir($instance);
        $remotePath = $this->joinRemotePath($rootPath, $normalizedPath);

        $sftp = $this->connect($instance);
        if (!$sftp->is_dir($remotePath)) {
            throw new \RuntimeException('Directory not found.');
        }

        $listing = $sftp->rawlist($remotePath, true);
        if ($listing === false) {
            throw new \RuntimeException('Unable to list directory.');
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

    public function readFile(Instance $instance, string $path, string $name): string
    {
        $remotePath = $this->resolveFilePath($instance, $path, $name);
        $sftp = $this->connect($instance);
        $content = $sftp->get($remotePath);
        if ($content === false) {
            throw new \RuntimeException('Failed to download file.');
        }

        return $content;
    }

    public function writeFile(Instance $instance, string $path, string $name, string $content): void
    {
        $remotePath = $this->resolveFilePath($instance, $path, $name);
        $sftp = $this->connect($instance);
        if (!$sftp->put($remotePath, $content, SFTP::SOURCE_STRING)) {
            throw new \RuntimeException('Failed to save file.');
        }
    }

    public function makeDirectory(Instance $instance, string $path, string $name): void
    {
        $remotePath = $this->resolveFilePath($instance, $path, $name);
        $sftp = $this->connect($instance);
        if (!$sftp->mkdir($remotePath, -1, true)) {
            throw new \RuntimeException('Failed to create directory.');
        }
    }

    public function delete(Instance $instance, string $path, string $name): void
    {
        $remotePath = $this->resolveFilePath($instance, $path, $name);
        $sftp = $this->connect($instance);
        if (!$sftp->delete($remotePath, true)) {
            throw new \RuntimeException('Failed to delete entry.');
        }
    }

    public function rename(Instance $instance, string $path, string $name, string $newName): void
    {
        $remotePath = $this->resolveFilePath($instance, $path, $name);
        $newPath = $this->resolveFilePath($instance, $path, $newName);
        $sftp = $this->connect($instance);
        if (!$sftp->rename($remotePath, $newPath)) {
            throw new \RuntimeException('Failed to rename entry.');
        }
    }

    public function chmod(Instance $instance, string $path, string $name, int $mode): void
    {
        $remotePath = $this->resolveFilePath($instance, $path, $name);
        $sftp = $this->connect($instance);
        if (!$sftp->chmod($mode, $remotePath, true)) {
            throw new \RuntimeException('Failed to update permissions.');
        }
    }

    public function extract(Instance $instance, string $path, string $name, string $destination): void
    {
        $remoteArchivePath = $this->resolveFilePath($instance, $path, $name);
        $normalizedDestination = $this->normalizeRelativePath($destination);
        $rootPath = $this->filesystemResolver->resolveInstanceDir($instance);
        $destinationPath = $this->joinRemotePath($rootPath, $normalizedDestination);

        $sftp = $this->connect($instance);
        $content = $sftp->get($remoteArchivePath);
        if ($content === false) {
            throw new \RuntimeException('Failed to download archive.');
        }

        $workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'easywi_extract_' . bin2hex(random_bytes(6));
        $archivePath = $workDir . DIRECTORY_SEPARATOR . $name;
        $extractPath = $workDir . DIRECTORY_SEPARATOR . 'extract';

        if (!mkdir($extractPath, 0755, true) && !is_dir($extractPath)) {
            throw new \RuntimeException('Failed to prepare extraction directory.');
        }

        if (file_put_contents($archivePath, $content) === false) {
            $this->cleanupWorkDir($workDir);
            throw new \RuntimeException('Failed to store archive.');
        }

        try {
            $lowerName = strtolower($name);
            if (str_ends_with($lowerName, '.zip')) {
                $zip = new \ZipArchive();
                if ($zip->open($archivePath) !== true) {
                    throw new \RuntimeException('Failed to open zip archive.');
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
                throw new \RuntimeException('Unsupported archive format.');
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
        $auth = $this->resolveAuthentication();

        $sftp = new SFTP($host, $port, 10);
        if ($auth !== null) {
            $loggedIn = $sftp->login($username, $auth);
        } else {
            $loggedIn = $sftp->login($username, $this->resolvePassword());
        }

        if (!$loggedIn) {
            throw new \RuntimeException('Unable to authenticate with SFTP server.');
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

        throw new \RuntimeException('SFTP host not configured.');
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

    private function resolvePassword(): string
    {
        $password = $this->settingsService->getSftpPassword();
        if (is_string($password) && $password !== '') {
            return $password;
        }

        throw new \RuntimeException('SFTP password not configured.');
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

    private function resolveFilePath(Instance $instance, string $path, string $name): string
    {
        $this->assertValidName($name);
        $rootPath = $this->filesystemResolver->resolveInstanceDir($instance);
        $normalizedPath = $this->normalizeRelativePath($path);
        $relative = $normalizedPath === '' ? $name : $normalizedPath . '/' . $name;

        return $this->joinRemotePath($rootPath, $relative);
    }

    private function joinRemotePath(string $rootPath, string $relative): string
    {
        $rootPath = rtrim($rootPath, '/');
        $relative = ltrim($relative, '/');

        return $relative === '' ? $rootPath : $rootPath . '/' . $relative;
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
                array_pop($safe);
                continue;
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
                throw new \RuntimeException('Failed to upload extracted file.');
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
