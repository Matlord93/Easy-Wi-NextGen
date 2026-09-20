<?php
declare(strict_types=1);
namespace App\Repository;
use App\Module\Core\Domain\Entity\NotificationPreference;
use App\Module\Core\Domain\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
final class NotificationPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, NotificationPreference::class); }
    public function findFor(User $recipient, string $category): ?NotificationPreference
    {
        return $this->findOneBy(['recipient' => $recipient, 'category' => $category]);
    }
}
