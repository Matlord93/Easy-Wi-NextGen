<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsFeatureToggle;
use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\MediaAssetRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/media')]
final class PublicCmsMediaController
{
    public function __construct(
        private readonly MediaAssetRepository $mediaRepository,
        private readonly SiteResolver $siteResolver,
        private readonly CmsFeatureToggle $featureToggle,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly ThemeResolver $themeResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'public_cms_media_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null || !$this->featureToggle->isEnabled($site, 'media')) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return new Response($this->twig->render('public/maintenance.html.twig', [
                'message' => $maintenance['message'],
                'graphic_path' => $maintenance['graphic_path'],
                'starts_at' => $maintenance['starts_at'],
                'ends_at' => $maintenance['ends_at'],
                'scope' => $maintenance['scope'],
            ]), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $assets = array_filter(
            $this->mediaRepository->findBySite($site),
            static fn (MediaAsset $asset): bool => str_starts_with((string) $asset->getMime(), 'image/'),
        );

        return new Response($this->twig->render('public/media/index.html.twig', [
            'assets' => array_map(static fn (MediaAsset $asset): array => [
                'path' => $asset->getPath(),
                'title' => $asset->getTitle(),
                'alt' => $asset->getAlt(),
                'created_at' => $asset->getCreatedAt(),
            ], $assets),
        ] + $this->themeContext($site, 'media', 'Media')));
    }

    /** @return array<string,mixed> */
    private function themeContext(Site $site, string $slug, string $title): array
    {
        $templateKey = $this->themeResolver->resolveThemeKey($site);

        return [
            'active_theme' => $templateKey,
            'template_key' => $templateKey,
            'page' => ['slug' => $slug, 'title' => $title],
            'cms_navigation' => $this->settingsProvider->getNavigationLinks($site),
            'cms_footer_links' => $this->settingsProvider->getFooterLinks($site),
            'cms_branding' => $this->settingsProvider->getBranding($site),
        ];
    }
}
