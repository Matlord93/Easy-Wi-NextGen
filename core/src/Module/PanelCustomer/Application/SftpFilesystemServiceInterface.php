<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\Application;

use App\Module\Core\Domain\Entity\Webspace;

interface SftpFilesystemServiceInterface
{
    /**
     * @return array<int, array{name: string, path: string, type: string, size: ?int, last_modified: ?int}>
     */
    public function list(Webspace $webspace, string $path): array;

    public function read(Webspace $webspace, string $path): string;

    /**
     * @param resource $stream
     */
    public function writeStream(Webspace $webspace, string $path, $stream): void;

    public function write(Webspace $webspace, string $path, string $content): void;

    public function delete(Webspace $webspace, string $path): void;

    public function move(Webspace $webspace, string $source, string $destination): void;

    public function fileSize(Webspace $webspace, string $path): int;

    public function normalizePath(string $path): string;
}
