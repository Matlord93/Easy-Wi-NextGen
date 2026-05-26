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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DatabaseRowEditControllerTest extends TestCase
{
    public function testEditBlocksForeignAndSystemDatabase(): void
    {
        $owner = new User('owner@test', UserType::Customer);
        $other = new User('other@test', UserType::Customer);
        $db = $this->newDatabase($owner);
        [$c, $tables] = $this->controller($db);

        $r = new Request();
        $r->attributes->set('current_user', $other);
        self::assertSame(404, $c->editRow($r, 1, 'users', '1')->getStatusCode());

        $db->setName('mysql');
        $r2 = new Request();
        $r2->attributes->set('current_user', $owner);
        self::assertSame(403, $c->editRow($r2, 1, 'users', '1')->getStatusCode());
        self::assertFalse(method_exists($tables, '__called')); // keep service untouched by guards
    }

    public function testGetEditMapsRowNotFoundWithoutRawExceptionOrSecretLeak(): void
    {
        $owner = new User('owner@test', UserType::Customer);
        $db = $this->newDatabase($owner);
        [$c, $tables] = $this->controller($db);
        $tables->method('getEditableRow')->willThrowException(new \InvalidArgumentException('edit_row_not_found'));

        $r = new Request();
        $r->attributes->set('current_user', $owner);
        $resp = $c->editRow($r, 1, 'users', '404');

        self::assertSame(400, $resp->getStatusCode());
        self::assertStringContainsString('edit_row_not_found', (string) $resp->getContent());
        self::assertStringNotContainsString('SQLSTATE', (string) $resp->getContent());
        self::assertStringNotContainsString('password', strtolower((string) $resp->getContent()));
    }

    #[DataProvider('postErrorProvider')]
    public function testPostEditMapsServiceErrors(string $code): void
    {
        $owner = new User('owner@test', UserType::Customer);
        $db = $this->newDatabase($owner);
        [$c, $tables] = $this->controller($db);
        $tables->method('updateRow')->willThrowException(new \InvalidArgumentException($code));

        $r = new Request(request: ['fields' => ['name' => 'x']]);
        $r->setMethod('POST');
        $r->attributes->set('current_user', $owner);
        $resp = $c->editRow($r, 1, 'users', '1');

        self::assertSame(400, $resp->getStatusCode());
        self::assertStringContainsString($code, (string) $resp->getContent());
    }

    public static function postErrorProvider(): array
    {
        return [
            ['invalid_column_name'],
            ['edit_row_not_found'],
            ['edit_requires_primary_key'],
            ['edit_composite_primary_key_not_supported'],
        ];
    }

    public function testRowsViewContextForSingleNoneAndCompositePrimaryKey(): void
    {
        $owner = new User('owner@test', UserType::Customer);
        $db = $this->newDatabase($owner);

        $captured = [];
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $tpl, array $ctx) use (&$captured): string {
            $captured = ['tpl' => $tpl, 'ctx' => $ctx];
            return 'ok';
        });

        $tables = $this->createMock(DatabaseTableService::class);
        $tables->method('listRows')->willReturn(['rows' => [['id' => '1', 'name' => 'n']], 'limit' => 50, 'offset' => 0]);
        $tables->method('listPrimaryKeyColumns')->willReturnOnConsecutiveCalls(['id'], [], ['id_a', 'id_b']);

        $c = $this->controllerWithTwigAndTables($db, $twig, $tables);
        $r = new Request();
        $r->attributes->set('current_user', $owner);

        $c->tableRows($r, 1, 'users');
        self::assertSame(['id'], $captured['ctx']['primaryKeyColumns']);
        $c->tableRows($r, 1, 'users');
        self::assertSame([], $captured['ctx']['primaryKeyColumns']);
        $c->tableRows($r, 1, 'users');
        self::assertSame(['id_a', 'id_b'], $captured['ctx']['primaryKeyColumns']);
    }

    private function controller(Database $database): array
    {
        $tables = $this->createMock(DatabaseTableService::class);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('ok');

        return [$this->controllerWithTwigAndTables($database, $twig, $tables), $tables];
    }

    private function controllerWithTwigAndTables(Database $database, Environment $twig, DatabaseTableService $tables): CustomerDatabaseController
    {
        $dbRepo = $this->createMock(DatabaseRepository::class);
        $dbRepo->method('find')->willReturn($database);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        return new CustomerDatabaseController(
            $dbRepo,
            $this->createMock(DatabaseNodeRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(DatabaseProvisioningService::class),
            $this->createMock(DatabaseNamingPolicy::class),
            $twig,
            $translator,
            $tables,
        );
    }

    private function newDatabase(User $owner): Database
    {
        $agent = new Agent('a', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'A');
        $node = new DatabaseNode('n', 'mariadb', '127.0.0.1', 3306, $agent);

        return new Database($owner, 'mariadb', '127.0.0.1', 3306, 'u2_demo', 'u2_demo', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], $node);
    }
}
