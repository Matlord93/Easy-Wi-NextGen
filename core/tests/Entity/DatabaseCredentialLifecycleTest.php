<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class DatabaseCredentialLifecycleTest extends TestCase
{
    public function testClearingOneTimeCredentialDoesNotDeleteOperationalPassword(): void
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('agent-db-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'DB Agent');
        $node = new DatabaseNode('db-node-1', 'mariadb', '127.0.0.1', 3306, $agent);
        $password = ['key_id' => 'k1', 'nonce' => 'n1', 'ciphertext' => 'c1'];
        $oneTime = ['key_id' => 'k2', 'nonce' => 'n2', 'ciphertext' => 'c2'];

        $database = new Database($customer, 'mariadb', '127.0.0.1', 3306, 'u2_demo', 'u2_demo', $password, $node);
        $database->setEncryptedOneTimeCredential($oneTime);

        $database->setEncryptedOneTimeCredential(null);

        self::assertSame($password, $database->getEncryptedPassword());
        self::assertNull($database->getEncryptedOneTimeCredential());
    }
}
