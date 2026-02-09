<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\CmsPageRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CmsTemplateManager
{
    public function __construct(
        private readonly CmsTemplateCatalog $templateCatalog,
        private readonly CmsPageRepository $pageRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(): array
    {
        return $this->templateCatalog->listTemplates();
    }

    public function getTemplate(string $key): ?array
    {
        return $this->templateCatalog->getTemplate($key);
    }

    /**
     * @param array<string, mixed> $template
     */
    public function applyTemplate(Site $site, array $template): void
    {
        $existingPages = $this->pageRepository->findBy(['site' => $site]);
        $existingSlugs = array_map(static fn (CmsPage $page) => $page->getSlug(), $existingPages);

        foreach ($template['pages'] ?? [] as $pageData) {
            if (!is_array($pageData)) {
                continue;
            }

            $slug = $pageData['slug'] ?? null;
            if (!is_string($slug) || $slug === '') {
                continue;
            }

            if (in_array($slug, $existingSlugs, true)) {
                continue;
            }

            $title = is_string($pageData['title'] ?? null) ? $pageData['title'] : $slug;
            $page = new CmsPage($site, $title, $slug, (bool) ($pageData['is_published'] ?? true));
            $this->entityManager->persist($page);

            $sortOrder = 1;
            foreach (($pageData['blocks'] ?? []) as $blockData) {
                if (!is_array($blockData)) {
                    continue;
                }

                $type = $blockData['type'] ?? null;
                $content = $blockData['content'] ?? null;
                if (!is_string($type) || $type === '' || !is_string($content)) {
                    continue;
                }

                $block = new CmsBlock($page, $type, $content, $sortOrder);
                $page->addBlock($block);
                $this->entityManager->persist($block);
                $sortOrder++;
            }
        }
    }
}
