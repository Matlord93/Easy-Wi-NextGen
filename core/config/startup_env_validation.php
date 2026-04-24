<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $defaults = [
        'REDIS_DSN' => 'redis://localhost:6379',
    ];

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
        if ((!is_string($value) || trim($value) === '') && isset($defaults[$key])) {
            $value = $defaults[$key];
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv(sprintf('%s=%s', $key, $value));
        }

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

    $env = strtolower((string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'dev'));
    if (in_array($env, ['prod', 'production', 'stage', 'staging'], true)) {
        $placeholderPatterns = [
            'APP_SECRET' => ['change_this_secret', 'changeme', 'placeholder', 'replace-with'],
            'AGENT_REGISTRATION_TOKEN' => ['change_this_secret', 'changeme', 'placeholder', 'replace-with'],
            'AUTH_IDENTIFIER_HASH_PEPPER' => ['change_this_identifier_pepper', 'changeme', 'placeholder', 'replace-with'],
            'APP_GITHUB_TOKEN' => ['token erstellen', 'changeme', 'placeholder', 'replace-with'],
        ];

        $placeholderKeys = [];
        foreach ($placeholderPatterns as $key => $patterns) {
            $value = strtolower(trim((string) ($_SERVER[$key] ?? $_ENV[$key] ?? getenv($key) ?? '')));
            if ($value === '') {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (str_contains($value, $pattern)) {
                    $placeholderKeys[] = $key;
                    break;
                }
            }
        }

        if ($placeholderKeys !== []) {
            throw new \RuntimeException(sprintf(
                'Startup validation failed: placeholder secrets detected in %s. Replace with real secrets from your secret manager.',
                implode(', ', $placeholderKeys)
            ));
        }
    }
};
