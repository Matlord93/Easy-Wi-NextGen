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
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\JobResult;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\JobResultStatus;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\UI\Controller\Customer\CustomerDatabaseController;
use App\Repository\DatabaseNodeRepository;
use App\Repository\DatabaseRepository;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomerDatabaseDetailsControllerTest extends TestCase
{
    public function testDetailsRouteIsRegisteredAsHtmlPage(): void
    {
        $method = new \ReflectionMethod(CustomerDatabaseController::class, 'details');
        $attributes = $method->getAttributes(Route::class);
        self::assertNotEmpty($attributes);

        /** @var Route $route */
        $route = $attributes[0]->newInstance();
        $path = method_exists($route, 'getPath') ? $route->getPath() : (property_exists($route, 'path') ? $route->path : null);
        $methods = method_exists($route, 'getMethods') ? $route->getMethods() : (property_exists($route, 'methods') ? $route->methods : []);

        self::assertSame('/{id}/details', $path);
        self::assertSame(['GET'], $methods);
    }

    public function testDetailsPageReceivesDatabaseAndAvailableCredentialJob(): void
    {
        [$controller, $request, $database, $job, $renderedContext] = $this->buildControllerWithDetailsContext();

        $response = $controller->details($request, 7);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('details-rendered', $response->getContent());
        self::assertSame($database, $renderedContext['context']['database']);
        self::assertSame($job->getId(), $renderedContext['context']['credentialJobId']);
        self::assertTrue($renderedContext['context']['credentialAvailable']);
        self::assertFalse($renderedContext['context']['credentialAlreadyConsumed']);
    }

    public function testPasswordButtonIsNotAvailableWithoutMatchingSuccessfulJob(): void
    {
        [$controller, $request, , , $renderedContext] = $this->buildControllerWithDetailsContext(includeSuccessfulJob: false);

        $response = $controller->details($request, 7);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($renderedContext['context']['credentialJobId']);
        self::assertFalse($renderedContext['context']['credentialAvailable']);
        self::assertFalse($renderedContext['context']['credentialAlreadyConsumed']);
    }

    public function testConsumedCredentialIsReportedToTemplate(): void
    {
        [$controller, $request, $database, , $renderedContext] = $this->buildControllerWithDetailsContext();
        $database->setEncryptedOneTimeCredential(null);

        $response = $controller->details($request, 7);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($renderedContext['context']['credentialAvailable']);
        self::assertTrue($renderedContext['context']['credentialAlreadyConsumed']);
    }

    public function testForeignUserGetsNotFound(): void
    {
        [$controller, $request] = $this->buildControllerWithDetailsContext();
        $request->attributes->set('current_user', new User('foreign@example.test', UserType::Customer));

        $response = $controller->details($request, 7);

        self::assertSame(404, $response->getStatusCode());
    }

    private function buildControllerWithDetailsContext(bool $includeSuccessfulJob = true): array
    {
        $customer = new User('owner@example.test', UserType::Customer);
        $this->setEntityId($customer, 5);
        $agent = new Agent('agent-db', ['key_id' => 'k', 'nonce' => 'n', 'ciphertext' => 'c'], 'DB Agent');
        $node = new DatabaseNode('db-node', 'mariadb', '10.0.0.7', 3306, $agent);
        $database = new Database($customer, 'mariadb', '10.0.0.7', 3306, 'u5_app', 'u5_app', null, $node);
        $this->setEntityId($database, 7);
        $database->setEncryptedOneTimeCredential(['key_id' => 'k2', 'nonce' => 'n2', 'ciphertext' => 'c2']);

        $job = new Job('database.create', ['database_id' => '7']);
        new JobResult($job, JobResultStatus::Succeeded, []);
        $foreignJob = new Job('database.rotate_password', ['database_id' => '8']);
        new JobResult($foreignJob, JobResultStatus::Succeeded, []);

        $dbRepo = $this->createMock(DatabaseRepository::class);
        $dbRepo->method('find')->willReturn($database);

        $jobRepo = $this->createMock(JobRepository::class);
        $jobRepo->method('findLatestByType')->willReturnCallback(static function (string $type) use ($includeSuccessfulJob, $job, $foreignJob): array {
            if ($type === 'database.create') {
                return $includeSuccessfulJob ? [$job] : [];
            }

            return [$foreignJob];
        });

        $renderedContext = new \ArrayObject();
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $template, array $context) use ($renderedContext): string {
            $renderedContext['template'] = $template;
            $renderedContext['context'] = $context;

            return 'details-rendered';
        });

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        $controller = new CustomerDatabaseController(
            $dbRepo,
            $this->createMock(DatabaseNodeRepository::class),
            $jobRepo,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AuditLogger::class),
            $this->createMock(DatabaseProvisioningService::class),
            $this->createMock(DatabaseNamingPolicy::class),
            $twig,
            $translator,
            $this->createMock(DatabaseTableService::class),
        );

        $request = new Request();
        $request->attributes->set('current_user', $customer);

        return [$controller, $request, $database, $job, $renderedContext];
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
