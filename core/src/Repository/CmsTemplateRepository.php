<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\CmsTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CmsTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CmsTemplate::class);
    }

    public function findOneByTemplateKey(string $templateKey): ?CmsTemplate
    {
        return $this->findOneBy(['templateKey' => strtolower(trim($templateKey))]);
    }
}
