<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Infrastructure\Config\DbConfigProvider;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

#[AutoconfigureTag('container.env_var_processor')]
final class DatabaseUrlEnvVarProcessor implements EnvVarProcessorInterface
{
    public function __construct(private readonly DbConfigProvider $configProvider)
    {
    }

    /** @param \Closure(string): mixed $getEnv */
    #[\Override]
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        if (!$this->configProvider->exists()) {
            return 'mysql://localhost?charset=utf8mb4';
        }

        $payload = $this->configProvider->load();
        if ($this->configProvider->validate($payload) !== []) {
            throw new DatabaseNotConfiguredException('Database configuration is invalid.');
        }

        // DBAL's DSN parser accepts connection parameters in the query string.
        // DsnParser decodes the query before parse_str decodes its values again.
        // Encode values for both passes so reserved password characters remain data.
        return 'mysql://localhost?'.http_build_query(
            array_map(
                static fn (string|int $value): string => rawurlencode((string) $value),
                $this->configProvider->toConnectionParams($payload),
            ),
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /** @return array{easywi_database_url: 'string'} */
    #[\Override]
    public static function getProvidedTypes(): array
    {
        return ['easywi_database_url' => 'string'];
    }
}
