<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\CmsTemplate;
use App\Module\Core\Domain\Entity\CmsTemplateVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CmsTemplateVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CmsTemplateVersion::class);
    }

    public function findActiveForTemplate(CmsTemplate $template): ?CmsTemplateVersion
    {
        return $this->findOneBy(['template' => $template, 'active' => true]);
    }
}
