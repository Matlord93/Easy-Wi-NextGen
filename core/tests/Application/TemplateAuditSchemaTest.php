<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Module\Core\Application\TemplateAuditService;
use App\Repository\TemplateRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class TemplateAuditSchemaTest extends TestCase
{
    public function testSelectsExistingUsageTableWithTemplateColumn(): void
    {
        $em = $this->entityManager();
        $em->getConnection()->executeStatement('CREATE TABLE instances (id INTEGER, template_id INTEGER)');

        self::assertSame('instances', $this->service($em)->resolveUsageTable());
    }

    public function testPrefersLegacyTableOnlyWhenItContainsTemplateReferences(): void
    {
        $em = $this->entityManager();
        $em->getConnection()->executeStatement('CREATE TABLE server_instances (id INTEGER)');
        $em->getConnection()->executeStatement('CREATE TABLE instances (id INTEGER, template_id INTEGER)');
        $service = $this->service($em);

        self::assertSame('instances', $service->resolveUsageTable());

        $em->getConnection()->executeStatement('ALTER TABLE server_instances ADD COLUMN template_id INTEGER');

        self::assertSame('server_instances', $service->resolveUsageTable());
    }

    public function testReturnsNullWithoutAUsageTableContainingTemplateReferences(): void
    {
        $em = $this->entityManager();
        $em->getConnection()->executeStatement('CREATE TABLE instances (id INTEGER)');

        self::assertNull($this->service($em)->resolveUsageTable());
    }

    private function service(EntityManager $em): TemplateAuditService
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return new TemplateAuditService(new TemplateRepository($registry), $em);
    }

    private function entityManager(): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([], true);
        $configuration->enableNativeLazyObjects(true);

        return new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $configuration,
        );
    }
}
