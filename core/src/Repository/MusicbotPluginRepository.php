<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotPlugin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MusicbotPlugin> */
class MusicbotPluginRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MusicbotPlugin::class);
    }

    /** @return MusicbotPlugin[] */
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC']);
    }

    public function findOneForCustomer(int $id, User $customer): ?MusicbotPlugin
    {
        return $this->findOneBy(['id' => $id, 'customer' => $customer]);
    }

    /** @return MusicbotPlugin[] */
    public function findByInstanceForCustomer(MusicbotInstance $instance, User $customer): array
    {
        return $this->findBy(['customer' => $customer, 'instance' => $instance], ['identifier' => 'ASC']);
    }

    public function findOneByIdentifierForInstance(string $identifier, MusicbotInstance $instance, User $customer): ?MusicbotPlugin
    {
        return $this->findOneBy(['identifier' => $identifier, 'instance' => $instance, 'customer' => $customer]);
    }
}
