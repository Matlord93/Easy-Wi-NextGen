<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DatabaseNamingPolicy;
use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\JobPayloadMasker;
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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DatabaseApiControllerJobsRouteTest extends TestCase
{
    public function testRouteIsRegistered(): void
    {
        $method = new \ReflectionMethod(DatabaseApiController::class, 'listJobs');
        $attributes = $method->getAttributes(Route::class);
        self::assertNotEmpty($attributes);

        /** @var Route $route */
        $route = $attributes[0]->newInstance();
        self::assertSame('/api/v1/customer/databases/{id}/jobs', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
    }

    public function testOwnerGetsOnlyOwnDatabaseJobsAndMaskedSecrets(): void
    {
        [$controller, $request, $database, $jobs] = $this->buildController();

        $response = $controller->listJobs($request, 4);
        self::assertSame(200, $response->getStatusCode());
        $json = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $json['jobs']);
        self::assertSame($jobs[0]->getId(), $json['jobs'][0]['id']);
        self::assertSame('[redacted]', $json['jobs'][0]['payload']['admin_secret']);
        self::assertSame('[redacted]', $json['jobs'][0]['result']['token']);
        self::assertArrayNotHasKey('password', $json['jobs'][0]);
        self::assertSame((string) $database->getId(), (string) $jobs[0]->getPayload()['database_id']);
    }

    public function testForeignUserGetsNotFound(): void
    {
        [$controller, $request] = $this->buildController();
        $request->attributes->set('current_user', new User('foreign@test', UserType::Customer));

        $response = $controller->listJobs($request, 4);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testUnknownDatabaseGetsDatabaseNotFound(): void
    {
        [$controller, $request] = $this->buildController(database: null);
        $response = $controller->listJobs($request, 4);

        self::assertSame(404, $response->getStatusCode());
        $json = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('database_not_found', $json['error_code']);
    }

    private function buildController(?Database $database = null): array
    {
        $customer = new User('owner@test', UserType::Customer);
        $agent = new Agent('a1', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'A');
        $node = new DatabaseNode('node1', 'mariadb', '127.0.0.1', 3306, $agent);
        $database ??= new Database($customer, 'mariadb', '127.0.0.1', 3306, 'u4_demo', 'u4_demo', null, $node);

        $ownerJob = new Job('database.create', ['database_id' => (string) $database->getId(), 'admin_secret' => 'top-secret']);
        new JobResult($ownerJob, JobResultStatus::Succeeded, ['token' => 'abc']);

        $otherJob = new Job('database.delete', ['database_id' => '999', 'password' => 'nope']);

        $dbRepo = $this->createMock(DatabaseRepository::class);
        $dbRepo->method('find')->willReturn($database);

        $jobRepo = $this->createMock(JobRepository::class);
        $jobRepo->method('findBy')->willReturn([$ownerJob, $otherJob]);

        $nodeRepo = $this->createMock(DatabaseNodeRepository::class);
        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $audit = $this->createMock(AuditLogger::class);
        $prov = $this->createMock(DatabaseProvisioningService::class);
        $naming = $this->createMock(DatabaseNamingPolicy::class);
        $encryption = $this->createMock(EncryptionService::class);

        $respFactory = $this->createMock(ResponseEnvelopeFactory::class);
        $respFactory->method('error')->willReturnCallback(static fn (Request $r, string $m, string $code, int $status) => new JsonResponse(['error_code' => $code], $status));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');

        $controller = new DatabaseApiController($dbRepo, $nodeRepo, $userRepo, $jobRepo, $em, $audit, $prov, $naming, $respFactory, $encryption, new JobPayloadMasker(), $translator);
        $request = new Request();
        $request->attributes->set('current_user', $customer);

        return [$controller, $request, $database, [$ownerJob, $otherJob]];
    }
}
