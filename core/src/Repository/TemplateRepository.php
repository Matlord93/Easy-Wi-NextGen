<?php

declare(strict_types=1);

namespace App\Repository;

use App\Module\Core\Domain\Entity\Template;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class TemplateRepository extends ServiceEntityRepository implements SharedStorageTemplateLocatorInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Template::class);
    }

    public function findSharedStorageVariantForIdentity(Template $template): ?Template
    {
        $displayName = trim($template->getDisplayName());
        $gameKey = trim($template->getGameKey());
        if ($displayName === '' || $gameKey === '') {
            return null;
        }

        /** @var list<Template> $matches */
        $matches = $this->findBy([
            'displayName' => $displayName,
            'gameKey' => $gameKey,
        ], ['id' => 'ASC']);

        foreach ($matches as $candidate) {
            if ($candidate->supportsSharedStorage()) {
                return $candidate;
            }
        }

        return null;
    }
}
