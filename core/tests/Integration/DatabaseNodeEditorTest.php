<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Application\EncryptionServiceInterface;
use App\Module\Core\Domain\Entity\Agent;
use App\Module\Core\Domain\Entity\AuditLog;
use App\Module\Core\Domain\Entity\Database;
use App\Module\Core\Domain\Entity\DatabaseNode;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminDatabaseNodeEditorController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class DatabaseNodeEditorTest extends KernelTestCase
{
    private array $previousDatabase;
    private EntityManagerInterface $em;
    private DatabaseNode $node;
    private User $customer;
    private Request $request;
    private EncryptionServiceInterface $encryption;

    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->encryption = new EncryptionService('editor-test', 'editor-test:'.base64_encode(random_bytes(32)));
        self::getContainer()->set(EncryptionServiceInterface::class, $this->encryption);
        $admin = new User('db-editor-admin@example.test', UserType::Admin);
        $this->customer = new User('db-editor-customer@example.test', UserType::Customer);
        foreach ([$admin, $this->customer] as $user) {
            $user->setPasswordHash('fixture');
            $this->em->persist($user);
        }
        $agent = new Agent('db-editor-agent', ['key_id' => 'fixture', 'nonce' => 'fixture', 'ciphertext' => 'fixture']);
        $agent->setRoles(['DB']);
        $this->node = new DatabaseNode('SQL original', 'mysql', 'database.example.test', 3306, $agent);
        $this->node->setAdminUser('admin');
        $this->node->setEncryptedAdminSecret($this->encryption->encrypt('old-admin-connection-secret'));
        $this->node->markHealthy('SELECT 1 ok');
        foreach ([$agent, $this->node] as $entity) {
            $this->em->persist($entity);
        } $this->em->flush();
        $this->request = Request::create('/admin/databases/nodes/'.$this->node->getId().'/edit', 'POST');
        $this->request->setSession(new Session(new MockArraySessionStorage()));
        $this->request->attributes->set('current_user', $admin);
        self::getContainer()->get(RequestStack::class)->push($this->request);
        $this->request->request->replace(['_token' => self::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('admin_database_node_edit_'.$this->node->getId())->getValue(), 'name' => 'SQL renamed', 'engine' => 'mysql', 'host' => 'database.example.test', 'port' => '3306', 'agent_id' => 'db-editor-agent', 'admin_user' => 'admin', 'admin_secret' => '', 'tls_mode' => 'off', 'ca_cert' => '', 'tags' => 'production, Frankfurt']);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(RequestStack::class)->pop();
        self::ensureKernelShutdown();
        foreach (['SERVER', 'ENV'] as $index => $global) {
            if (null === $this->previousDatabase[$index]) {
                unset($GLOBALS['_'.$global]['DATABASE_URL']);
            } else {
                $GLOBALS['_'.$global]['DATABASE_URL'] = $this->previousDatabase[$index];
            }
        }
        parent::tearDown();
    }

    public function testBlankPasswordPreservesCiphertextAndNameChangePreservesHealth(): void
    {
        $original = $this->node->getEncryptedAdminSecret();
        $controller = self::getContainer()->get(AdminDatabaseNodeEditorController::class);
        self::assertSame(302, $controller->edit($this->request, $this->node->getId())->getStatusCode());
        $this->em->refresh($this->node);
        self::assertSame('SQL renamed', $this->node->getName());
        self::assertSame($original, $this->node->getEncryptedAdminSecret());
        self::assertSame('healthy', $this->node->getHealthStatus());
        self::assertSame(['production', 'Frankfurt'], $this->node->getTags());
        $this->request->setMethod('GET');
        $page = $controller->edit($this->request, $this->node->getId());
        self::assertSame(200, $page->getStatusCode());
        self::assertStringNotContainsString('old-admin-connection-secret', $page->getContent());
        self::assertStringNotContainsString($original['ciphertext'], $page->getContent());
        self::assertStringContainsString('#pm-admin-databases-nodes', $page->getContent());
    }

    public function testNewConnectionPasswordIsEncryptedAndInvalidatesTheOldHealthResult(): void
    {
        $this->request->request->set('admin_secret', 'replacement-admin-connection-secret');
        $controller = self::getContainer()->get(AdminDatabaseNodeEditorController::class);
        self::assertSame(302, $controller->edit($this->request, $this->node->getId())->getStatusCode());
        $this->em->refresh($this->node);
        self::assertSame('replacement-admin-connection-secret', $this->encryption->decrypt($this->node->getEncryptedAdminSecret()));
        self::assertSame('unknown', $this->node->getHealthStatus());
        self::assertNull($this->node->getLastCheckedAt());
        self::assertNull($this->node->getHealthMessage());
        $audit = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'database.node.updated']);
        self::assertNotNull($audit);
        self::assertStringNotContainsString('replacement-admin-connection-secret', json_encode($audit->getPayload()));
        $this->request->setMethod('GET');
        self::assertStringNotContainsString('replacement-admin-connection-secret', $controller->edit($this->request, $this->node->getId())->getContent());
    }

    public function testAssignedDatabasesPreventRetargetingWithoutChangingStoredData(): void
    {
        $database = new Database($this->customer, 'mysql', $this->node->getHost(), 3306, 'customer_database', 'customer_user', null, $this->node);
        $this->em->persist($database);
        $this->em->flush();
        $this->request->request->set('host', 'another.example.test');
        $this->request->request->set('admin_secret', 'must-not-be-written');
        $response = self::getContainer()->get(AdminDatabaseNodeEditorController::class)->edit($this->request, $this->node->getId());
        self::assertSame(422, $response->getStatusCode());
        $this->em->refresh($this->node);
        self::assertSame('database.example.test', $this->node->getHost());
        self::assertSame('SQL original', $this->node->getName());
        self::assertSame('old-admin-connection-secret', $this->encryption->decrypt($this->node->getEncryptedAdminSecret()));
        self::assertSame($this->node, $database->getNode());
        self::assertSame('database.example.test', $database->getHost());
        self::assertSame(0, $this->em->getRepository(AuditLog::class)->count(['action' => 'database.node.updated']));
        self::assertStringNotContainsString('must-not-be-written', $response->getContent());
        self::assertStringNotContainsString('value="another.example.test"', $response->getContent());
    }

    public function testUnusedServerCanChangeItsConnectionTarget(): void
    {
        $this->request->request->set('host', 'new-database.example.test');
        $this->request->request->set('port', '3307');
        self::assertSame(302, self::getContainer()->get(AdminDatabaseNodeEditorController::class)->edit($this->request, $this->node->getId())->getStatusCode());
        $this->em->refresh($this->node);
        self::assertSame('new-database.example.test', $this->node->getHost());
        self::assertSame(3307, $this->node->getPort());
        self::assertSame('unknown', $this->node->getHealthStatus());
    }

    public static function invalidSettings(): iterable
    {
        yield 'DSN host injection' => ['host', 'example.test;dbname=unexpected'];
        yield 'non-integer port' => ['port', '3306.5'];
        yield 'unknown engine' => ['engine', 'unknown'];
        yield 'unknown TLS mode' => ['tls_mode', 'invalid'];
        yield 'missing agent' => ['agent_id', 'not-an-agent'];
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsLeaveTheNodeUnchanged(string $field, string $value): void
    {
        $this->request->request->set($field, $value);
        self::assertSame(422, self::getContainer()->get(AdminDatabaseNodeEditorController::class)->edit($this->request, $this->node->getId())->getStatusCode());
        $this->em->refresh($this->node);
        self::assertSame('SQL original', $this->node->getName());
        self::assertSame('database.example.test', $this->node->getHost());
        self::assertSame('healthy', $this->node->getHealthStatus());
    }

    public function testCustomerAccessAndInvalidCsrfAreRejected(): void
    {
        $controller = self::getContainer()->get(AdminDatabaseNodeEditorController::class);
        $this->request->request->set('_token', 'invalid');
        try {
            $controller->edit($this->request, $this->node->getId());
            self::fail('Invalid CSRF must fail.');
        } catch (AccessDeniedHttpException) {
            self::assertSame('SQL original', $this->node->getName());
        }
        $this->request->attributes->set('current_user', $this->customer);
        $this->expectException(AccessDeniedHttpException::class);
        $controller->edit($this->request, $this->node->getId());
    }
}
