<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\Application;

use App\Module\Core\Domain\Entity\User;
use Symfony\Component\Filesystem\Filesystem;

final class AdminSshKeyService
{
    public function __construct(
        private readonly string $authorizedKeysPath,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function storeKey(User $admin, string $publicKey): void
    {
        $publicKey = trim($publicKey);
        if ($publicKey === '') {
            return;
        }

        $directory = dirname($this->authorizedKeysPath);
        $this->filesystem->mkdir($directory, 0700);

        $existing = '';
        if (is_file($this->authorizedKeysPath)) {
            $existing = (string) file_get_contents($this->authorizedKeysPath);
            if (str_contains($existing, $publicKey)) {
                return;
            }
        }

        $entry = $this->withComment($publicKey, $admin);
        $prefix = $existing !== '' && !str_ends_with($existing, "\n") ? "\n" : '';

        file_put_contents(
            $this->authorizedKeysPath,
            $prefix . $entry . "\n",
            FILE_APPEND | LOCK_EX,
        );

        $this->filesystem->chmod($this->authorizedKeysPath, 0600);
    }

    private function withComment(string $publicKey, User $admin): string
    {
        $parts = preg_split('/\s+/', trim($publicKey), 3);
        if (!is_array($parts) || count($parts) >= 3) {
            return $publicKey;
        }

        $comment = sprintf('easywi-admin-%s %s', $admin->getId() ?? 'unknown', $admin->getEmail());

        return sprintf('%s %s %s', $parts[0], $parts[1], $comment);
    }
}
