<?php

declare(strict_types=1);

namespace App\Tests\Module\Core\Application;

use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use PHPUnit\Framework\TestCase;

final class DatabaseProvisioningServiceTest extends TestCase
{
    public function testCreatePayloadDoesNotContainPasswordFields(): void
    {
        $service = new DatabaseProvisioningService();
        $db = $this->newDatabase();

        $jobs = $service->buildCreateJobs($db, 'agent-db-1');
        self::assertCount(1, $jobs);
        self::assertSame('database.create', $jobs[0]->getType());
        self::assertArrayNotHasKey('password', $jobs[0]->getPayload());
        self::assertArrayNotHasKey('encrypted_password', $jobs[0]->getPayload());
        self::assertSame('private', $jobs[0]->getPayload()['connection_policy'] ?? null);
    }

    public function testRotateUsesNewJobTypeAndNoSecretPayload(): void
    {
        $service = new DatabaseProvisioningService();
        $db = $this->newDatabase();

        $job = $service->buildPasswordRotateJob($db, 'agent-db-1');
        self::assertSame('database.rotate_password', $job->getType());
        self::assertArrayNotHasKey('password', $job->getPayload());
        self::assertArrayNotHasKey('encrypted_password', $job->getPayload());
    }

    private function newDatabase(): Database
    {
        $user = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('agent-db-1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'DB Agent');
        $node = new DatabaseNode('db-node-1', 'mariadb', '127.0.0.1', 3306, $agent);

        return new Database($user, 'mariadb', '127.0.0.1', 3306, 'cust_db_1', 'cust_user_1', null, $node);
    }
}
