<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\Database;

class DatabaseNodeInspector
{
    public function __construct(private readonly EncryptionService $encryptionService)
    {
    }

    /** @return array{database_exists:bool,user_exists:bool,grants_ok:bool} */
    public function inspect(Database $database): array
    {
        $node = $database->getNode();
        if ($node === null || strtolower($database->getEngine()) !== 'mariadb') {
            return ['database_exists' => true, 'user_exists' => true, 'grants_ok' => true];
        }

        $adminUser = trim((string) $node->getAdminUser());
        $encryptedSecret = $node->getEncryptedAdminSecret();
        if ($adminUser === '' || !is_array($encryptedSecret)) {
            return ['database_exists' => false, 'user_exists' => false, 'grants_ok' => false];
        }

        $adminSecret = $this->encryptionService->decrypt($encryptedSecret);
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=information_schema;charset=utf8mb4', $node->getHost(), $node->getPort()),
            $adminUser,
            $adminSecret,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5],
        );

        $dbStmt = $pdo->prepare('SELECT 1 FROM SCHEMATA WHERE SCHEMA_NAME = :db LIMIT 1');
        $dbStmt->execute(['db' => $database->getName()]);
        $databaseExists = (bool) $dbStmt->fetchColumn();

        $userStmt = $pdo->prepare('SELECT 1 FROM mysql.user WHERE user = :user LIMIT 1');
        $userStmt->execute(['user' => $database->getUsername()]);
        $userExists = (bool) $userStmt->fetchColumn();

        $grantStmt = $pdo->prepare('SELECT 1 FROM SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA = :db AND GRANTEE LIKE :grantee LIMIT 1');
        $grantStmt->execute(['db' => $database->getName(), 'grantee' => "%'".$database->getUsername()."'%"]);
        $grantsOk = (bool) $grantStmt->fetchColumn();

        return ['database_exists' => $databaseExists, 'user_exists' => $userExists, 'grants_ok' => $grantsOk];
    }
}

