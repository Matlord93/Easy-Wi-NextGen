<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

final class CmsTemplateDeploymentManager
{
    public function __construct(private readonly string $deployRoot)
    {
    }

    public function deploy(string $templateKey, string $versionSourcePath): string
    {
        $base = $this->templateBaseDir($templateKey);
        $releases = $base.'/releases';
        if (!is_dir($releases) && !mkdir($releases, 0775, true) && !is_dir($releases)) {
            throw new \RuntimeException('Unable to initialize release storage.');
        }

        $releaseName = date('YmdHis').'-'.substr(hash('sha256', $versionSourcePath.microtime(true)), 0, 8);
        $releaseDir = $releases.'/'.$releaseName;

        $this->copyDir($versionSourcePath, $releaseDir);
        $this->switchCurrent($base, $releaseDir);

        return $releaseDir;
    }

    public function rollback(string $templateKey): string
    {
        $releases = $this->templateBaseDir($templateKey).'/releases';
        $candidates = array_values(array_filter(scandir($releases) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
        rsort($candidates);
        if (count($candidates) < 2) {
            throw new \RuntimeException('No rollback candidate found.');
        }

        $target = $releases.'/'.$candidates[1];
        $this->switchCurrent($this->templateBaseDir($templateKey), $target);

        return $target;
    }

    private function switchCurrent(string $base, string $target): void
    {
        $tmp = $base.'/current.next';
        $current = $base.'/current';

        if (is_link($tmp) || is_file($tmp)) {
            unlink($tmp);
        }

        if (!symlink($target, $tmp)) {
            throw new \RuntimeException('Cannot create temporary symlink for deployment.');
        }

        if (is_link($current) || is_file($current)) {
            unlink($current);
        }

        if (!rename($tmp, $current)) {
            throw new \RuntimeException('Cannot activate template release.');
        }
    }

    private function templateBaseDir(string $templateKey): string
    {
        return rtrim($this->deployRoot, '/').'/'.strtolower(trim($templateKey));
    }

    private function copyDir(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException('Template source directory missing.');
        }

        if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new \RuntimeException('Cannot create deployment release directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination.'/'.$relative;

            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException('Cannot create deployment directory segment.');
                }
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create deployment parent directory.');
            }

            if (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException('Cannot copy template file to deployment release.');
            }
        }
    }
}
