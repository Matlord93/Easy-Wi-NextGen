<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Domain\Entity\TicketTemplate;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\TicketCategory;
use App\Module\Core\Domain\Enum\TicketPriority;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelAdmin\UI\Controller\Admin\AdminTicketController;
use App\Repository\TicketTemplateRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminTicketTemplateTest extends TestCase
{
    public function testCreateConvertsValidatedFormValuesIntoEnumArguments(): void
    {
        $admin = new User('admin@example.test', UserType::Admin);
        $admin->setId(1);
        $persisted = null;
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            self::assertInstanceOf(TicketTemplate::class, $entity);
            (new \ReflectionProperty($entity, 'id'))->setValue($entity, 7);
            $persisted = $entity;
        });
        $manager->expects(self::once())->method('flush');
        $controller = $this->controller($manager);
        $response = $controller->createTemplate($this->request($admin));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/tickets?template_created=7', $response->headers->get('Location'));
        self::assertInstanceOf(TicketTemplate::class, $persisted);
        self::assertSame(TicketCategory::Billing, $persisted->getCategory());
        self::assertSame(TicketPriority::High, $persisted->getPriority());
    }

    public function testUpdateUsesEnumSettersAndKeepsTheTemplateOwner(): void
    {
        $admin = new User('admin@example.test', UserType::Admin);
        $admin->setId(1);
        $template = new TicketTemplate($admin, 'Old title', 'Old subject', TicketCategory::General, TicketPriority::Normal, 'Old body');
        (new \ReflectionProperty($template, 'id'))->setValue($template, 9);
        $inner = $this->createStub(EntityRepository::class);
        $inner->method('find')->willReturn($template);
        $repository = new TicketTemplateRepository($this->createStub(ManagerRegistry::class));
        (new \ReflectionProperty(ServiceEntityRepository::class, 'repository'))->setValue($repository, $inner);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())->method('flush');
        $controller = $this->controller($manager);
        (new \ReflectionProperty($controller, 'ticketTemplateRepository'))->setValue($controller, $repository);
        $response = $controller->updateTemplate($this->request($admin), 9);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/tickets?template_updated=9', $response->headers->get('Location'));
        self::assertSame($admin, $template->getAdmin());
        self::assertSame(TicketCategory::Billing, $template->getCategory());
        self::assertSame(TicketPriority::High, $template->getPriority());
    }

    private function controller(EntityManagerInterface $manager): AdminTicketController
    {
        $controller = (new \ReflectionClass(AdminTicketController::class))->newInstanceWithoutConstructor();
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        foreach (['entityManager' => $manager, 'auditLogger' => $this->createStub(AuditLoggerInterface::class), 'csrfTokenManager' => $csrf] as $property => $dependency) {
            (new \ReflectionProperty($controller, $property))->setValue($controller, $dependency);
        }
        return $controller;
    }

    private function request(User $admin): Request
    {
        $request = Request::create('/admin/tickets/templates', 'POST', ['title' => 'Billing reply', 'subject' => 'Invoice', 'category' => 'billing', 'priority' => 'high', 'body' => 'We are checking your invoice.']);
        $request->attributes->set('current_user', $admin);
        return $request;
    }
}
