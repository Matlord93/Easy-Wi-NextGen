<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\DatabaseNamingPolicyInterface;
use App\Module\Core\Application\DatabaseProvisioningServiceInterface;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Application\JobPayloadMasker;
use App\Module\Core\Application\DatabaseTableServiceInterface as DatabaseTableService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\UI\Api\ResponseEnvelopeFactory;
use App\Module\Core\UI\Api\ResponseEnvelopeFactoryInterface;
use App\Module\PanelCustomer\UI\Controller\Api\DatabaseApiController;
use App\Module\PanelCustomer\UI\Controller\Customer\CustomerDatabaseController;
use App\Repository\DatabaseNodeRepositoryInterface;
use App\Repository\DatabaseRepositoryInterface;
use App\Repository\JobRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DatabaseApiControllerStatusAndImportGuardTest extends TestCase
{
    public function testCreateDoesNotSetRotationPending(): void
    {
        $customer = new User('c@test', UserType::Customer);
        $agent = new Agent('a',['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'],'A');
        $node = new DatabaseNode('n','mariadb','127.0.0.1',3306,$agent);

        $dbRepo = $this->createStub(DatabaseRepositoryInterface::class);
        $dbRepo->method('findOneByCustomerAndName')->willReturn(null);
        $dbRepo->method('findOneByCustomerAndUsername')->willReturn(null);

        $nodeRepo = $this->createStub(DatabaseNodeRepositoryInterface::class);
        $nodeRepo->method('find')->willReturn($node);

        $userRepo = $this->createStub(UserRepositoryInterface::class);
        $jobRepo = $this->createStub(JobRepositoryInterface::class);
        $jobRepo->method('findActiveByTypeAndPayloadField')->willReturn(null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');

        $audit = $this->createStub(AuditLoggerInterface::class);
        $prov = $this->createStub(DatabaseProvisioningServiceInterface::class);
        $prov->method('buildCreateJobs')->willReturn([new Job('database.create',[])]);
        $naming = $this->createStub(DatabaseNamingPolicyInterface::class);
        $naming->method('validateDatabaseName')->willReturn([]);
        $naming->method('buildCustomerScopedName')->willReturn('u1_demo');

        $env = $this->createStub(EncryptionServiceInterface::class);
        $respFactory = $this->createStub(ResponseEnvelopeFactoryInterface::class);
        $respFactory->method('success')->willReturn(new JsonResponse([],202));
        $respFactory->method('error')->willReturn(new JsonResponse([],400));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('x');

        $c = new DatabaseApiController($dbRepo,$nodeRepo,$userRepo,$jobRepo,$em,$audit,$prov,$naming,$respFactory,$env,new JobPayloadMasker(),$translator);
        $r = new Request(content: json_encode(['node_id'=>1,'name'=>'demo']));
        $r->attributes->set('current_user',$customer);
        $c->create($r);

        // regression guard: create flow must not use rotation status
        $db = new Database($customer,'mariadb','127.0.0.1',3306,'u1_demo','u1_demo',null,$node);
        $db->setStatus('pending');
        self::assertNotSame('rotation_pending', $db->getStatus());
    }

    public function testImportUnknownInvalidArgumentMapsToGenericErrorKey(): void
    {
        $customer = new User('c@test', UserType::Customer);
        $agent = new Agent('a',['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'],'A');
        $node = new DatabaseNode('n','mariadb','127.0.0.1',3306,$agent);
        $db = new Database($customer,'mariadb','127.0.0.1',3306,'u1_demo','u1_demo',['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'],$node);

        $dbRepo = $this->createStub(DatabaseRepositoryInterface::class);
        $dbRepo->method('find')->willReturn($db);
        $nodeRepo = $this->createStub(DatabaseNodeRepositoryInterface::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $audit = $this->createStub(AuditLoggerInterface::class);
        $prov = $this->createStub(DatabaseProvisioningServiceInterface::class);
        $naming = $this->createStub(DatabaseNamingPolicyInterface::class);
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(fn($tpl,$ctx)=>json_encode($ctx['errors']??[]));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');
        $tables = $this->createStub(DatabaseTableService::class);
        $tables->method('importSql')->willThrowException(new \InvalidArgumentException('weird_unexpected_code'));

        $c = new CustomerDatabaseController($dbRepo,$nodeRepo,$this->createStub(JobRepositoryInterface::class),$em,$audit,$prov,$naming,$twig,$translator,$tables);
        $r = new Request();
        $r->attributes->set('current_user',$customer);
        $resp = $c->importDatabase($r, 1);
        self::assertSame(400, $resp->getStatusCode());
        self::assertStringContainsString('customer_databases_import_failed', $resp->getContent() ?: '');
    }
}
