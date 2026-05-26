<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DatabaseNamingPolicy;
use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\JobResult;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\JobResultStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\PanelCustomer\UI\Controller\Api\DatabaseApiController;
use App\Repository\DatabaseNodeRepository;
use App\Repository\DatabaseRepository;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DatabaseCredentialRevealFlowTest extends TestCase
{
    public function testRevealSucceededWorksOnceAndKeepsEncryptedPassword(): void
    {
        [$controller, $request, $database, $job] = $this->buildController('rotation_succeeded');

        $resp1 = $controller->consumeCredential($request, 7, $job->getId());
        self::assertSame(200, $resp1->getStatusCode());
        self::assertNull($database->getEncryptedOneTimeCredential());
        self::assertNotNull($database->getEncryptedPassword());

        $resp2 = $controller->consumeCredential($request, 7, $job->getId());
        self::assertSame(410, $resp2->getStatusCode());
    }

    public function testRevealPendingAndFailedAreBlocked(): void
    {
        foreach (['rotation_pending', 'rotation_failed'] as $status) {
            [$controller, $request, , $job] = $this->buildController($status);
            $resp = $controller->consumeCredential($request, 7, $job->getId());
            self::assertSame(409, $resp->getStatusCode());
        }
    }

    public function testRevealSystemDatabaseIsBlocked(): void
    {
        [$controller, $request, $database, $job] = $this->buildController('rotation_succeeded');
        $database->setName('mysql');
        $resp = $controller->consumeCredential($request, 7, $job->getId());
        self::assertSame(403, $resp->getStatusCode());
    }



    public function testRevealForeignUserIsBlockedAndCredentialStaysIntact(): void
    {
        [$controller, $request, $database, $job] = $this->buildController('rotation_succeeded');
        $foreign = new User('foreign@example.test', UserType::Customer);
        $request->attributes->set('current_user', $foreign);

        $resp = $controller->consumeCredential($request, 7, $job->getId());

        self::assertSame(404, $resp->getStatusCode());
        self::assertNotNull($database->getEncryptedOneTimeCredential());
        self::assertNotNull($database->getEncryptedPassword());
    }

    private function buildController(string $dbStatus): array
    {
        $customer = new User('customer@example.test', UserType::Customer);
        $agent = new Agent('agent-db-1', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], 'DB Agent');
        $node = new DatabaseNode('db-node-1', 'mariadb', '127.0.0.1', 3306, $agent);
        $database = new Database($customer, 'mariadb', '127.0.0.1', 3306, 'u2_demo', 'u2_demo', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], $node);
        $database->setStatus($dbStatus);
        $database->setEncryptedOneTimeCredential(['key_id'=>'k2','nonce'=>'n2','ciphertext'=>'c2']);

        $job = new Job('database.rotate_password', ['database_id' => '7']);
        new JobResult($job, JobResultStatus::Succeeded, []);

        $dbRepo = $this->createMock(DatabaseRepository::class);
        $dbRepo->method('find')->willReturn($database);
        $nodeRepo = $this->createMock(DatabaseNodeRepository::class);
        $userRepo = $this->createMock(UserRepository::class);
        $jobRepo = $this->createMock(JobRepository::class);
        $jobRepo->method('find')->willReturn($job);

        $conn = $this->createMock(Connection::class);
        $conn->method('transactional')->willReturnCallback(static fn(callable $fn) => $fn());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('find')->willReturn($database);
        $em->method('flush')->willReturn(null);

        $audit = $this->createMock(AuditLogger::class);
        $prov = $this->createMock(DatabaseProvisioningService::class);
        $naming = $this->createMock(DatabaseNamingPolicy::class);

        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('decrypt')->willReturnMap([
            [['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], 'stable-pass'],
            [['key_id'=>'k2','nonce'=>'n2','ciphertext'=>'c2'], 'reveal-pass'],
        ]);

        $respFactory = $this->createMock(ResponseEnvelopeFactory::class);
        $respFactory->method('error')->willReturnCallback(static fn(Request $r, string $m, string $code, int $status) => new JsonResponse(['error_code'=>$code], $status));
        $respFactory->method('success')->willReturnCallback(static fn(Request $r, string $id, string $m, int $status, array $extra=[]) => new JsonResponse($extra, $status));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');

        $controller = new DatabaseApiController($dbRepo, $nodeRepo, $userRepo, $jobRepo, $em, $audit, $prov, $naming, $respFactory, $encryption, $translator);

        $request = new Request();
        $request->attributes->set('current_user', $customer);

        return [$controller, $request, $database, $job];
    }
}
