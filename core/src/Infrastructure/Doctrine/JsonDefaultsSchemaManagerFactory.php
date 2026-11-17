<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Schema\SchemaManagerFactory;

final class JsonDefaultsSchemaManagerFactory implements SchemaManagerFactory
{
    public static function supports(Connection $connection): bool
    {
        if (!$connection->getDatabasePlatform() instanceof MySQLPlatform) {
            return false;
        }
        return version_compare($connection->getServerVersion(), '8.4.0', '>=');
    }

    #[\Override]
    public function createSchemaManager(Connection $connection): AbstractSchemaManager
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof MySQLPlatform && self::supports($connection)) {
            return new JsonDefaultsSchemaManager($connection, $platform);
        }

        return (new DefaultSchemaManagerFactory())->createSchemaManager($connection);
    }
}
