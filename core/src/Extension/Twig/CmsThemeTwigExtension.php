<?php

declare(strict_types=1);

namespace App\Extension\Twig;

use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\CmsTranslations;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\Application\BlockRenderer\ShopProductsBlockRenderer;
use App\Module\Core\Application\ModuleRegistry;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Repository\CmsPageRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

final class CmsThemeTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly CmsSiteResolver $sites,
        private readonly CmsSettingsProvider $settings,
        private readonly CmsPageRepository $pages,
        private readonly ModuleRegistry $modules,
        private readonly ThemeResolver $themes,
        private readonly ShopProductsBlockRenderer $shopProducts,
        private readonly CmsTranslations $messages,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('cms_site_context', [$this, 'siteContext']), new TwigFunction('cms_shop_preview', [$this, 'shopPreview'], ['is_safe' => ['html']])];
    }

    /** @return list<TwigFilter> */
    #[\Override]
    public function getFilters(): array
    {
        return [new TwigFilter('cms_trans', [$this, 'translate'])];
    }

    /** @param array<string, scalar|\Stringable|null> $parameters */
    public function translate(string $key, array $parameters = [], ?string $locale = null): string
    {
        return $this->messages->trans($key, $parameters, $locale);
    }

    /** @return array<string, mixed> */
    public function siteContext(): array
    {
        $request = $this->requests->getCurrentRequest();
        $site = null === $request ? null : $this->sites->resolve($request);
        if (null === $site) {
            return ['navigation' => [['label' => 'Home', 'url' => '/', 'external' => false]], 'footer' => [], 'branding' => [], 'modules' => [], 'shop' => false];
        }

        $toggles = $this->settings->getModuleToggles($site);
        $shop = $this->modules->isEnabled('shop') && $this->modules->isEnabled('billing');
        $links = $this->settings->getNavigationLinks($site);
        if (($this->settings->findForSite($site)?->getHeaderLinksJson() ?? []) === []) {
            $links = [];
            foreach ($this->pages->findBy(['site' => $site, 'isPublished' => true], ['id' => 'ASC']) as $page) {
                $links[] = ['label' => $page->getTitle(), 'url' => 'startseite' === $page->getSlug() ? '/' : '/'.$page->getSlug(), 'external' => false];
            }
        }
        // Hosting exposes enabled services even when a saved menu predates them.
        if (($this->settings->findForSite($site)?->getHeaderLinksJson() ?? []) === [] || 'hosting' === $request?->attributes->getString('_cms_preview_theme', $this->themes->resolveThemeKey($site))) {
            foreach (['blog' => '/blog', 'events' => '/events', 'team' => '/teams', 'forum' => '/forum', 'media' => '/media', 'gameserver' => '/servers'] as $module => $url) {
                if ($toggles[$module] ?? false) {
                    $links[] = ['label' => $this->messages->trans('nav_'.$module), 'url' => $url, 'external' => false];
                }
            }
            $links[] = ['label' => $this->messages->trans('nav_contact'), 'url' => '/kontakt', 'external' => false];
            if ($shop) {
                $links[] = ['label' => $this->messages->trans('nav_shop'), 'url' => '/shop', 'external' => false];
            }
        }

        $branding = $this->settings->getBranding($site);
        if (!preg_match('/^#[a-f0-9]{6}$/iD', $branding['primary_color'])) {
            $branding['primary_color'] = '';
        }

        return [
            'navigation' => $this->visibleLinks($links, $toggles, $shop),
            'footer' => $this->visibleLinks($this->settings->getFooterLinks($site), $toggles, $shop),
            'branding' => $branding,
            'modules' => $toggles,
            'shop' => $shop,
        ];
    }

    public function shopPreview(): string
    {
        return $this->shopProducts->render(['limit' => 6]);
    }

    /**
     * @param list<array{label:string,url:string,external:bool}> $links
     * @param array<string, bool>                                $toggles
     *
     * @return list<array{label:string,url:string,external:bool}>
     */
    private function visibleLinks(array $links, array $toggles, bool $shop): array
    {
        $visible = [];
        $seen = [];
        foreach ($links as $link) {
            $url = $link['url'];
            if (!preg_match('~^(?:/(?!/)|https?://|mailto:|tel:|\#)~i', $url)) {
                continue;
            }
            $key = rtrim($url, '/');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (str_starts_with($url, '/')) {
                $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
                $section = explode('/', $path)[0];
                $module = match ($section) {
                    'team', 'teams' => 'team',
                    'server', 'servers', 'gameserver' => 'gameserver',
                    default => $section,
                };
                if (($toggles[$module] ?? true) === false || ('shop' === $section && !$shop)) {
                    continue;
                }
            }
            $visible[] = $link;
        }

        return $visible;
    }
}
