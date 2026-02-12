<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\CookieCatalogProvider;
use App\Module\Core\Application\SiteResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class PublicCookiePolicyController
{
    public function __construct(
        private readonly SiteResolver $siteResolver,
        private readonly ThemeResolver $themeResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly CookieCatalogProvider $cookieCatalogProvider,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/cookies', name: 'public_cookie_policy', methods: ['GET'], priority: 12)]
    public function index(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $templateKey = $this->themeResolver->resolveThemeKey($site);

        return new Response($this->twig->render('public/cookies/policy.html.twig', [
            'cookies' => $this->cookieCatalogProvider->all(),
            'active_theme' => $templateKey,
            'template_key' => $templateKey,
            'page' => ['slug' => 'cookies', 'title' => 'Cookie-Richtlinie'],
            'cms_navigation' => $this->settingsProvider->getNavigationLinks($site),
            'cms_footer_links' => $this->settingsProvider->getFooterLinks($site),
            'cms_branding' => $this->settingsProvider->getBranding($site),
        ]));
    }

    #[Route(path: '/cookie-richtlinie', name: 'public_cookie_policy_legacy', methods: ['GET'], priority: 10)]
    public function legacyRedirect(): Response
    {
        return new RedirectResponse('/cookies', Response::HTTP_MOVED_PERMANENTLY);
    }
}
