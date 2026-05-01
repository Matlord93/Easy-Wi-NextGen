<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\CmsTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class CmsTemplateSeeder
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CmsTemplateCatalog $catalog,
    ) {
    }

    /**
     * @return array{created: int, skipped: int}
     */
    public function seed(?EntityManagerInterface $entityManager = null): array
    {
        $entityManager = $entityManager ?? $this->registry->getManager();
        $repository = $entityManager->getRepository(CmsTemplate::class);

        $created = 0;
        $skipped = 0;

        foreach ($this->catalog->listTemplates() as $templateData) {
            $key = (string) ($templateData['key'] ?? '');
            if ($key === '') {
                continue;
            }

            if ($repository->findOneBy(['templateKey' => $key]) !== null) {
                $skipped++;
                continue;
            }

            $label = (string) ($templateData['label'] ?? $key);
            $template = new CmsTemplate($key, $label);
            $template->setActive(true);

            $entityManager->persist($template);
            $created++;
        }

        if ($created > 0) {
            $entityManager->flush();
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
