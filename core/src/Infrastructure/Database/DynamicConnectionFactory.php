<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Infrastructure\Config\DbConfigProvider;
use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;

final class DynamicConnectionFactory
{
    public function __construct(
        private readonly ConnectionFactory $inner,
        private readonly DbConfigProvider $configProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $mappingTypes
     */
    public function createConnection(
        array $params,
        ?Configuration $config = null,
        array $mappingTypes = [],
    ): Connection {
        if (!$this->configProvider->exists()) {
            throw new DatabaseNotConfiguredException('Database configuration is missing.');
        }

        $payload = $this->configProvider->load();
        $validationErrors = $this->configProvider->validate($payload);
        if ($validationErrors !== []) {
            throw new DatabaseNotConfiguredException('Database configuration is invalid.');
        }

        $params = array_merge($params, $this->configProvider->toConnectionParams($payload));

        return $this->inner->createConnection($params, $config, $mappingTypes);
    }
}
