<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotPlaylistService;
use App\Module\Musicbot\Application\MusicbotRadioCatalogServiceInterface;
use App\Module\Musicbot\Domain\Entity\MusicbotCustomerLimits;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\UserRepositoryInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MusicbotCatalogContractsTest extends KernelTestCase
{
    private array $previousDatabase;
    private EntityManagerInterface $entities;
    private User $first;
    private User $second;

    protected function setUp(): void
    {
        $this->previousDatabase = [$_SERVER['DATABASE_URL'] ?? null, $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->entities = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->entities))->createSchema($this->entities->getMetadataFactory()->getAllMetadata());
        $this->first = new User('catalog-first@example.test', UserType::Customer);
        $this->second = new User('catalog-second@example.test', UserType::Customer);
        foreach ([$this->first, $this->second] as $customer) {
            $customer->setPasswordHash('test-only');
            $this->entities->persist($customer);
        }
        $this->entities->flush();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        foreach (['SERVER', 'ENV'] as $index => $global) {
            if ($this->previousDatabase[$index] === null) {
                unset($GLOBALS['_'.$global]['DATABASE_URL']);
            } else {
                $GLOBALS['_'.$global]['DATABASE_URL'] = $this->previousDatabase[$index];
            }
        }
        parent::tearDown();
    }

    public function testRealCatalogKeepsFavoritesHistoryAndQuotaIsolatedPerCustomer(): void
    {
        $catalog = self::getContainer()->get(MusicbotRadioCatalogServiceInterface::class);
        $station = new MusicbotRadioStation(null, 'Shared station', 'https://radio.example.test/live', true);
        $this->entities->persist($station);
        $this->entities->flush();
        $catalog->addFavorite($this->first, $station);
        $catalog->recordHistory($this->first, $station);
        $this->entities->flush();
        self::assertSame($station->getId(), $catalog->getFavorites($this->first)[0]['id']);
        self::assertTrue($catalog->getFavorites($this->first)[0]['is_favorite']);
        self::assertSame([], $catalog->getFavorites($this->second));
        self::assertSame($station->getId(), $catalog->getHistory($this->first)[0]['id']);
        self::assertSame([], $catalog->getHistory($this->second));
        self::assertFalse($catalog->search($this->second)['stations'][0]['is_favorite']);
        $limits = new MusicbotCustomerLimits($this->first);
        $limits->setAllowWebradio(false);
        $this->entities->persist($limits);
        $this->entities->flush();
        $this->expectException(MusicbotQuotaExceededException::class);
        $catalog->getFavorites($this->first);
    }

    public function testRealPlaylistContractsKeepQuotaAndCustomerOwnership(): void
    {
        $playlists = self::getContainer()->get(MusicbotPlaylistService::class);
        $playlist = $playlists->createPlaylist($this->first, ' Preserved playlist ');
        self::assertSame('Preserved playlist', $playlist->getName());
        self::assertSame([$playlist], $playlists->playlistsForCustomer($this->first));
        self::assertSame([], $playlists->playlistsForCustomer($this->second));
        try {
            $playlists->deletePlaylist($this->second, $playlist);
            self::fail('Another customer must not delete the playlist.');
        } catch (\RuntimeException) {
            self::assertSame([$playlist], $playlists->playlistsForCustomer($this->first));
        }
    }

    public function testDoctrineQueryOptionsRemainUsableThroughTheRepositoryContract(): void
    {
        $users = self::getContainer()->get(UserRepositoryInterface::class);
        self::assertSame($this->second, $users->findOneBy([], ['id' => 'DESC']));
        self::assertSame($this->first, $users->find($this->first->getId(), LockMode::NONE));
        self::assertSame(2, $users->count([]));
        self::assertSame($this->first, $users->createQueryBuilder('customer')
            ->where('customer.id = :id')
            ->setParameter('id', $this->first->getId())
            ->getQuery()->getOneOrNullResult());
    }
}
