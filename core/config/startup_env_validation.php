<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $requiredEnvKeys = [
        'APP_SECRET',
        'DEFAULT_URI',
        'MAILER_DSN',
        'MESSENGER_TRANSPORT_DSN',
        'REDIS_DSN',
        'AGENT_SIGNATURE_SKEW_SECONDS',
        'AGENT_NONCE_TTL_SECONDS',
        'APP_AGENT_RELEASE_CACHE_TTL',
        'APP_CHANGELOG_CACHE_TTL',
        'APP_CORE_RELEASE_CACHE_TTL',
        'APP_CORE_UPDATE_RELEASES_DIR',
        'APP_CORE_UPDATE_CURRENT_SYMLINK',
        'APP_CORE_UPDATE_LOCK_FILE',
    ];

    $missing = [];
    foreach ($requiredEnvKeys as $key) {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (!is_string($value) || trim($value) === '') {
            $missing[] = $key;
        }
    }

    if ($missing !== []) {
        throw new \RuntimeException(sprintf(
            "Startup validation failed: missing required environment variables: %s. Fill them in .env/.env.local or provide them via your secret manager.",
            implode(', ', $missing)
        ));
    }
};
