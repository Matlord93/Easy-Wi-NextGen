<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use App\Repository\MusicbotRadioStationRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MusicbotRadioStationRepositoryTest extends KernelTestCase
{
    public function testCatalogCountUsesTheSameFiltersAsThePaginatedSearch(): void
    {
        self::bootKernel();
        $configured = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $configured);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager = new EntityManager($connection, $configured->getConfiguration());
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(MusicbotRadioStation::class)]);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('getManagerForClass')->willReturn($manager);
        $repository = new MusicbotRadioStationRepository($registry);

        try {
            foreach ([
                ['Radio0', 128, 'Rock', 'DE', true, true],
                ['JazzFM', 256, '0', 'FR', true, true],
                ['Quiet', 64, 'Ambient', 'GB', true, true],
                ['Inactive', 128, 'Hidden', 'IT', true, false],
                ['Private', 128, 'Secret', 'ES', false, true],
            ] as [$name, $bitrate, $genre, $country, $global, $active]) {
                $station = new MusicbotRadioStation(null, $name, 'https://radio.example.test/stream', $global);
                $station->setBitrate($bitrate);
                $station->setGenre($genre);
                $station->setCountry($country);
                $station->setLanguage('de');
                $station->setFormat('mp3');
                $station->setActive($active);
                $manager->persist($station);
            }
            $manager->flush();

            foreach ([
                [['min_bitrate' => 128, 'max_bitrate' => 192], 1],
                [['max_bitrate' => 160], 2],
                [['min_bitrate' => 256], 1],
                [['query' => '0'], 2],
                [['genre' => 'ROCK', 'country' => 'de', 'language' => 'DE', 'format' => 'MP3'], 1],
                [['active_only' => false], 4],
                [['query' => [], 'genre' => false, 'min_bitrate' => [], 'limit' => [], 'offset' => []], 3],
            ] as [$filters, $expected]) {
                self::assertCount($expected, $repository->searchCatalog($filters));
                self::assertSame($expected, $repository->countCatalog($filters));
            }
            self::assertCount(1, $repository->searchCatalog(['limit' => 1, 'offset' => 1]));
            self::assertSame(3, $repository->countCatalog(['limit' => 1, 'offset' => 1]));
            self::assertSame(['0', 'Ambient', 'Rock'], $repository->getDistinctGenres());
            self::assertSame(['DE', 'FR', 'GB'], $repository->getDistinctCountries());
        } finally {
            $manager->close();
            $connection->close();
        }
    }
}
