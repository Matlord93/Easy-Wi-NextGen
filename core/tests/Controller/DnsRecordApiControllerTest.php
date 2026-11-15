<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\DnsRecordHelper;
use App\Module\Core\Domain\Entity\DnsRecord;
use App\Module\Core\Domain\Entity\Domain;
use App\Module\Core\Domain\Entity\Job;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\PanelCustomer\UI\Controller\Api\DnsRecordApiController;
use App\Repository\DnsRecordRepository;
use App\Repository\DomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DnsRecordApiControllerTest extends TestCase
{
    public function testValidatedDnsFieldsReachTheRecordAndQueuedJob(): void
    {
        $customer = $this->customer(10);
        $domain = new Domain($customer, null, 'example.test');
        (new \ReflectionProperty($domain, 'id'))->setValue($domain, 2);
        $domains = $this->createMock(DomainRepository::class);
        $domains->expects(self::once())->method('find')->with(2)->willReturn($domain);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::exactly(2))->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof DnsRecord) {
                self::assertSame('MX', $entity->getType());
                self::assertSame('mail.example.test.', $entity->getContent());
                self::assertSame(3600, $entity->getTtl());
                self::assertSame(10, $entity->getPriority());
                (new \ReflectionProperty($entity, 'id'))->setValue($entity, 3);
            } else {
                self::assertInstanceOf(Job::class, $entity);
                self::assertSame('dns.record.create', $entity->getType());
            }
        });
        $manager->expects(self::exactly(2))->method('flush');
        $response = $this->controller($domains, $manager)->create($this->request($customer, [
            'domain_id' => '2', 'name' => '@', 'type' => 'mx', 'content' => 'mail.example.test', 'ttl' => '3600', 'priority' => '10',
        ]));
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(3, $data['id']);
        self::assertSame(2, $data['domain_id']);
        self::assertSame(3600, $data['ttl']);
        self::assertSame(10, $data['priority']);
        self::assertNotEmpty($data['job_id']);
    }

    public function testMalformedFieldsReturnValidationErrorsBeforeDatabaseWork(): void
    {
        $domains = $this->createMock(DomainRepository::class);
        $domains->expects(self::never())->method('find');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::never())->method('flush');

        $response = $this->controller($domains, $manager)->create($this->request($this->customer(10), [
            'domain_id' => '2', 'name' => '@', 'type' => ['MX'], 'content' => ['invalid'], 'ttl' => ['invalid'], 'priority' => ['invalid'],
        ]));
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertContains('Record type is invalid.', $data['errors']);
        self::assertContains('TTL is required.', $data['errors']);
    }

    public function testValidatedFieldsDoNotBypassDomainOwnership(): void
    {
        $domain = new Domain($this->customer(20), null, 'other.test');
        $domains = $this->createStub(DomainRepository::class);
        $domains->method('find')->willReturn($domain);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('persist');

        $response = $this->controller($domains, $manager)->create($this->request($this->customer(10), [
            'domain_id' => 2, 'name' => '@', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600,
        ]));

        self::assertSame(403, $response->getStatusCode());
    }

    private function controller(DomainRepository $domains, EntityManagerInterface $manager): DnsRecordApiController
    {
        return new DnsRecordApiController(new DnsRecordRepository($this->createStub(ManagerRegistry::class)), $domains, $manager, $this->createStub(AuditLogger::class), new DnsRecordHelper(), $this->createStub(TranslatorInterface::class));
    }

    private function customer(int $id): User
    {
        $customer = new User('customer'.$id.'@example.test', UserType::Customer);
        $customer->setId($id);

        return $customer;
    }

    private function request(User $customer, array $data): Request
    {
        $request = Request::create('/api/dns/records', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data, JSON_THROW_ON_ERROR));
        $request->attributes->set('current_user', $customer);

        return $request;
    }
}
