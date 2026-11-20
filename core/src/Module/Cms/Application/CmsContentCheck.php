<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Infrastructure\Filesystem\ConfinedLocalPath;
use App\Module\Core\Application\ModuleRegistry;
use App\Module\Core\Domain\Entity\CmsBlock;
use App\Module\Core\Domain\Entity\CmsPage;
use App\Repository\CmsBlockRepository;
use App\Repository\CmsPageRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CmsContentCheck
{
    public function __construct(
        private CmsContentReferences $references,
        private CmsPageRepository $pages,
        private CmsBlockRepository $blocks,
        private CmsSettingsProvider $settings,
        private ModuleRegistry $modules,
        private PageResolver $pageResolver,
        #[Autowire('%kernel.project_dir%/public')]
        private string $publicDirectory,
    ) {
    }

    /**
     * @param list<CmsBlock>|null $blocks
     *
     * @return array{checked:int,skipped:int,truncated:bool,issues:list<array{source:string,block_id:?int,kind:string,target:string,code:string}>}
     */
    public function inspect(CmsPage $page, ?array $blocks = null): array
    {
        $site = $page->getSite();
        $pages = $this->pages->findPublicationStates($site);
        $toggles = $this->settings->getModuleToggles($site);
        $references = [];
        $truncated = false;
        $blocks ??= $this->blocks->findBy(['page' => $page], ['sortOrder' => 'ASC']);
        foreach ($blocks as $block) {
            if ($block->getPage() !== $page) {
                continue;
            }
            $extracted = $this->references->extract($block);
            $truncated = $truncated || $extracted['truncated'];
            foreach ($extracted['references'] as $reference) {
                $references[] = $reference + ['source' => 'block', 'block_id' => $block->getId()];
            }
            if (count($references) > 500) {
                $truncated = true;
                break;
            }
        }
        // The new themes generate navigation when no links are configured.
        $navigation = ($this->settings->findForSite($site)?->getHeaderLinksJson() ?? []) === [] ? [] : $this->settings->getNavigationLinks($site);
        foreach (['navigation' => $navigation, 'footer' => $this->settings->getFooterLinks($site)] as $source => $links) {
            foreach ($links as $link) {
                if (count($references) >= 500) {
                    $truncated = true;
                    break;
                }
                $references[] = ['source' => $source, 'block_id' => null, 'kind' => 'link', 'url' => $link['url']];
            }
        }
        $references[] = ['source' => 'logo', 'block_id' => null, 'kind' => 'media', 'url' => $this->settings->getBranding($site)['logo_path']];
        $truncated = $truncated || count($references) > 500;
        $checked = 0;
        $skipped = 0;
        $issues = [];
        $seen = [];
        $cache = [];
        foreach (array_slice($references, 0, 500) as $reference) {
            $url = trim($reference['url']);
            if ('' === $url) {
                continue;
            }
            $key = $reference['source'].'|'.($reference['block_id'] ?? 'site').'|'.$reference['kind'].'|'.$url;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $resultKey = $reference['kind'].'|'.$url;
            $result = $cache[$resultKey] ??= $this->checkReference($url, $reference['kind'], $site->getHost(), $pages, $toggles);
            if ('unchecked' === $result['code']) {
                ++$skipped;
                continue;
            }
            ++$checked;
            if ('ok' !== $result['code']) {
                $issues[] = ['source' => $reference['source'], 'block_id' => $reference['block_id'], 'kind' => $reference['kind'], 'target' => $result['target'], 'code' => $result['code']];
            }
        }

        return ['checked' => $checked, 'skipped' => $skipped, 'truncated' => $truncated, 'issues' => $issues];
    }

    /**
     * @param array<string,bool> $pages
     * @param array<string,bool> $toggles
     *
     * @return array{code:string,target:string}
     */
    private function checkReference(string $url, string $kind, string $host, array $pages, array $toggles): array
    {
        $parts = parse_url($url);
        $target = is_array($parts) ? ($parts['path'] ?? '') : '';
        if (false === $parts || preg_match('/[\x00-\x1f\x7f\\\\]/', $url) || isset($parts['user']) || isset($parts['pass'])) {
            return ['code' => 'invalid_url', 'target' => mb_substr($target, 0, 180)];
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['', 'http', 'https'], true)) {
            return ['code' => in_array($scheme, ['javascript', 'vbscript', 'file'], true) ? 'invalid_url' : 'unchecked', 'target' => mb_substr($target, 0, 180)];
        }
        if ('' !== $scheme && !isset($parts['host'])) {
            return ['code' => 'invalid_url', 'target' => mb_substr($target, 0, 180)];
        }
        if (isset($parts['host']) && strtolower($parts['host']) !== strtolower($host)) {
            return ['code' => 'unchecked', 'target' => ''];
        }
        // Fragment and query-only references keep the current page.
        if ('' === $target) {
            return ['code' => 'unchecked', 'target' => ''];
        }
        $decoded = rawurldecode($target);
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded)) {
            return ['code' => 'invalid_path', 'target' => mb_substr($target, 0, 180)];
        }
        $segments = [];
        foreach (explode('/', $decoded) as $segment) {
            if ('..' === $segment) {
                array_pop($segments);
            } elseif ('' !== $segment && '.' !== $segment) {
                $segments[] = $segment;
            }
        }
        $path = '/'.implode('/', $segments);
        $target = mb_substr($path, 0, 180);
        $static = 1 === preg_match('~^/(?:uploads|images|themes|assets|build|bundles|css|js)/~', $path) || (1 === count($segments) && str_contains($segments[0], '.'));
        if ('media' === $kind && !$static) {
            return ['code' => 'unchecked', 'target' => $target];
        }
        if ($static) {
            // Treat a deployment's public uploads symlink as its configured root;
            // individual symlinks must stay inside that root.
            $root = str_starts_with($path, '/uploads/') ? $this->publicDirectory.'/uploads' : $this->publicDirectory;
            $relative = str_starts_with($path, '/uploads/') ? substr($path, 9) : ltrim($path, '/');
            $file = ConfinedLocalPath::resolve($root, $relative);

            return ['code' => null !== $file && is_file($file) ? 'ok' : 'media_missing', 'target' => $target];
        }
        $slug = trim($path, '/');
        if ('' === $slug) {
            return ['code' => in_array(true, $pages, true) ? 'ok' : 'page_missing', 'target' => '/'];
        }
        $section = explode('/', $slug)[0];
        $module = match ($section) {
            'teams' => 'team', 'servers' => 'gameserver',
            default => $section,
        };
        if (($toggles[$module] ?? true) === false || ('shop' === $section && (!$this->modules->isEnabled('shop') || !$this->modules->isEnabled('billing')))) {
            return ['code' => 'module_disabled', 'target' => $target];
        }
        if (str_contains($slug, '/') || $this->pageResolver->isReservedSlug($slug) || in_array($slug, ['kontakt', 'contact', 'cookies', 'cookie-richtlinie', 'cookie-preferences', 'cookie-preferenzen', 'cookie-einstellungen', 'preview'], true)) {
            return ['code' => 'unchecked', 'target' => $target];
        }

        return ['code' => isset($pages[$slug]) ? ($pages[$slug] ? 'ok' : 'page_draft') : 'page_missing', 'target' => $target];
    }
}
