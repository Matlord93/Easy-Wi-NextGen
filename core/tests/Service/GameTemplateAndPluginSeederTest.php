<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Module\Core\Application\GameTemplateSeeder;
use App\Module\Core\Domain\Entity\GamePlugin;
use App\Module\Core\Domain\Entity\Template;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GameTemplateAndPluginSeederTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->rebuildSchema();
    }

    public function testPluginTableExistsWithCurrentColumns(): void
    {
        $schemaManager = $this->entityManager->getConnection()->createSchemaManager();

        self::assertTrue($schemaManager->tablesExist(['game_plugins']));
        self::assertFalse($schemaManager->tablesExist(['game_template_plugins']));

        $columns = array_change_key_case($schemaManager->listTableColumns('game_plugins'), CASE_LOWER);
        foreach (['id', 'template_id', 'name', 'version', 'checksum', 'download_url', 'description', 'extract_subdir', 'install_mode', 'created_at', 'updated_at'] as $column) {
            self::assertArrayHasKey($column, $columns);
        }
    }

    public function testSeederCreatesStandardTemplates(): void
    {
        $result = $this->seeder()->seedTemplatesOnly($this->entityManager);

        self::assertGreaterThan(0, $result);
        self::assertInstanceOf(Template::class, $this->entityManager->getRepository(Template::class)->findOneBy(['gameKey' => 'cs2']));
        self::assertInstanceOf(Template::class, $this->entityManager->getRepository(Template::class)->findOneBy(['gameKey' => 'minecraft_paper_all']));
    }

    public function testSeederCreatesStandardPluginsWhenTemplatesExist(): void
    {
        $result = $this->seeder()->seed($this->entityManager);

        self::assertGreaterThan(0, $result['templates']);
        self::assertGreaterThan(0, $result['plugins']);
        self::assertInstanceOf(GamePlugin::class, $this->entityManager->getRepository(GamePlugin::class)->findOneBy(['name' => 'MetaMod:Source']));
        self::assertInstanceOf(GamePlugin::class, $this->entityManager->getRepository(GamePlugin::class)->findOneBy(['name' => 'EssentialsX']));
    }

    public function testSeederIsIdempotent(): void
    {
        $this->seeder()->seed($this->entityManager);
        $firstCount = (int) $this->entityManager->getRepository(GamePlugin::class)->count([]);

        $secondResult = $this->seeder()->seed($this->entityManager);
        $secondCount = (int) $this->entityManager->getRepository(GamePlugin::class)->count([]);

        self::assertSame(0, $secondResult['templates']);
        self::assertSame(0, $secondResult['plugins']);
        self::assertSame($firstCount, $secondCount);
    }

    private function seeder(): GameTemplateSeeder
    {
        return self::getContainer()->get(GameTemplateSeeder::class);
    }

    private function rebuildSchema(): void
    {
        $tool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        if ($metadata === []) {
            return;
        }

        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->entityManager->clear();
    }
}
