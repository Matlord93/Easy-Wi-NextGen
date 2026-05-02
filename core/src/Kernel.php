<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\Compiler\RedisExtensionCheckPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RedisExtensionCheckPass());
    }

    public function getCacheDir(): string
    {
        $configuredCacheDir = $this->configuredDirectoryFromEnv('EASYWI_CACHE_DIR');
        if ($configuredCacheDir !== null) {
            return $configuredCacheDir;
        }

        $defaultCacheDir = parent::getCacheDir();
        if ($this->ensureDirectoryIsWritable($defaultCacheDir)) {
            return $defaultCacheDir;
        }

        $fallbackCacheDir = $this->buildRuntimeFallbackDirectory('cache');
        $this->ensureDirectoryIsWritable($fallbackCacheDir);

        return $fallbackCacheDir;
    }

    public function getLogDir(): string
    {
        $configuredLogDir = $this->configuredDirectoryFromEnv('EASYWI_LOG_DIR');
        if ($configuredLogDir !== null) {
            return $configuredLogDir;
        }

        $defaultLogDir = parent::getLogDir();
        if ($this->ensureDirectoryIsWritable($defaultLogDir)) {
            return $defaultLogDir;
        }

        $fallbackLogDir = $this->buildRuntimeFallbackDirectory('log');
        $this->ensureDirectoryIsWritable($fallbackLogDir);

        return $fallbackLogDir;
    }

    private function configuredDirectoryFromEnv(string $envKey): ?string
    {
        $value = $_SERVER[$envKey] ?? $_ENV[$envKey] ?? null;
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return rtrim($value, '/');
    }

    private function buildRuntimeFallbackDirectory(string $type): string
    {
        return sprintf('%s/easywi/%s/%s', rtrim(sys_get_temp_dir(), '/'), $this->environment, $type);
    }

    private function ensureDirectoryIsWritable(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        return is_writable($directory);
    }
}
