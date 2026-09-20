<?php
declare(strict_types=1);
namespace App\Repository;
use App\Module\Core\Domain\Entity\ShopCoupon;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
final class ShopCouponRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ShopCoupon::class); }
    public function findActiveByCode(int $siteId, string $code): ?ShopCoupon
    {
        return $this->findOneBy(['siteId' => $siteId, 'code' => strtoupper(trim($code)), 'active' => true]);
    }
}
