<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DatabaseNamingPolicy;
use App\Module\Core\Application\DatabaseProvisioningService;
use App\Module\Core\Application\DatabaseTableService;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\UI\Controller\Customer\CustomerDatabaseController;
use App\Repository\DatabaseNodeRepository;
use App\Repository\DatabaseRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DatabaseTableControllerAccessTest extends TestCase
{
    public function testStructureAndRowsBlockForeignAndSystemDatabases(): void
    {
        $owner = new User('owner@test', UserType::Customer);
        $other = new User('other@test', UserType::Customer);
        $db = $this->newDatabase($owner);

        $dbRepo = $this->createMock(DatabaseRepository::class);
        $dbRepo->method('find')->willReturn($db);
        $nodeRepo = $this->createMock(DatabaseNodeRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $audit = $this->createMock(AuditLogger::class);
        $prov = $this->createMock(DatabaseProvisioningService::class);
        $naming = $this->createMock(DatabaseNamingPolicy::class);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('ok');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('err');
        $tables = $this->createMock(DatabaseTableService::class);

        $c = new CustomerDatabaseController($dbRepo, $nodeRepo, $this->createMock(JobRepository::class), $em, $audit, $prov, $naming, $twig, $translator, $tables);

        $r = new Request();
        $r->attributes->set('current_user', $other);
        self::assertSame(404, $c->tableStructure($r, 1, 'users')->getStatusCode());
        self::assertSame(404, $c->tableRows($r, 1, 'users')->getStatusCode());

$r2 = new Request();
        $r2->attributes->set('current_user', $other);
        self::assertSame(404, $c->importDatabase($r2, 1)->getStatusCode());

        $db->setName('mysql');
        $r->attributes->set('current_user', $owner);
        self::assertSame(403, $c->tableStructure($r, 1, 'users')->getStatusCode());
        self::assertSame(403, $c->tableRows($r, 1, 'users')->getStatusCode());

        // export paths guarded as well
        $r->attributes->set('current_user', $other);
        self::assertSame(404, $c->exportDatabase($r, 1)->getStatusCode());
        self::assertSame(404, $c->exportTable($r, 1, 'users')->getStatusCode());

$r2 = new Request();
        $r2->attributes->set('current_user', $other);
        self::assertSame(404, $c->importDatabase($r2, 1)->getStatusCode());

        $db->setName('mysql');
        $r->attributes->set('current_user', $owner);
        self::assertSame(403, $c->exportDatabase($r, 1)->getStatusCode());
        self::assertSame(403, $c->exportTable($r, 1, 'users')->getStatusCode());

        $r3 = new Request();
        $r3->attributes->set('current_user', $owner);
        self::assertSame(400, $c->importDatabase($r3, 1)->getStatusCode());

        self::assertSame(404, $c->importDatabase(new Request(), 1)->getStatusCode());

    }

    private function newDatabase(User $owner): Database
    {
        $agent = new Agent('a', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], 'A');
        $node = new DatabaseNode('n', 'mariadb', '127.0.0.1', 3306, $agent);
        return new Database($owner, 'mariadb', '127.0.0.1', 3306, 'u2_demo', 'u2_demo', ['key_id'=>'k','nonce'=>'n','ciphertext'=>'c'], $node);
    }

    public function testSqlExportIsANosniffAttachmentAndPreservesSqlQuotes(): void
    {
        $owner = new User('owner@test', UserType::Customer);
        $database = $this->newDatabase($owner);
        $repository = $this->createStub(DatabaseRepository::class);
        $repository->method('find')->willReturn($database);
        $tables = $this->createStub(DatabaseTableService::class);
        $line = "INSERT INTO t VALUES ('</script><img src=x onerror=alert(1)>', '\"quoted\"');";
        $tables->method('exportDatabaseSql')->willReturn([$line]);
        $controller = new CustomerDatabaseController(
            $repository, $this->createStub(DatabaseNodeRepository::class), $this->createStub(JobRepository::class),
            $this->createStub(EntityManagerInterface::class), $this->createStub(AuditLogger::class),
            $this->createStub(DatabaseProvisioningService::class), $this->createStub(DatabaseNamingPolicy::class),
            $this->createStub(Environment::class), $this->createStub(TranslatorInterface::class), $tables,
        );
        $request = new Request();
        $request->attributes->set('current_user', $owner);
        $response = $controller->exportDatabase($request, 1);
        self::assertSame('application/sql; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        ob_start();
        try {
            $response->sendContent();
            self::assertSame($line."\n", ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}
