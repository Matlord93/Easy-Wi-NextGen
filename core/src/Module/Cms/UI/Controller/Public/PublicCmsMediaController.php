<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsFeatureToggle;
use App\Module\Cms\Application\CmsMediaGallery;
use App\Module\Cms\Application\CmsMediaThumbnails;
use App\Module\Cms\UI\Http\CmsMediaThumbnailResponseFactory;
use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\UI\Http\MaintenancePageResponseFactory;
use App\Module\Cms\Application\CmsSiteResolver;
use App\Module\Core\Domain\Entity\MediaAsset;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\MediaAssetRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route(path: '/media')]
final class PublicCmsMediaController
{
    public function __construct(
        private readonly MediaAssetRepository $mediaRepository,
        private readonly CmsSiteResolver $siteResolver,
        private readonly CmsFeatureToggle $featureToggle,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly MaintenancePageResponseFactory $maintenancePageResponseFactory,
        private readonly ThemeResolver $themeResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly CmsMediaGallery $gallery,
        private readonly CmsMediaThumbnails $thumbnails,
        private readonly CmsMediaThumbnailResponseFactory $thumbnailResponse,
    ) {
    }

    #[Route(path: '', name: 'public_cms_media_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null || !$this->featureToggle->isEnabled($site, 'media')) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return $this->maintenancePageResponseFactory->create($maintenance);
        }

        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        $pageLimit = intdiv(PHP_INT_MAX, 24);
        $page = max(1, min($pageLimit, $request->query->getInt('page', 1)));

        return new Response($this->twig->render('public/media/index.html.twig', $this->gallery->page($site, $search, $page) + [
            'search' => $search, 'current_page' => $page, 'page_limit' => $pageLimit,
        ] + $this->themeContext($site, 'media', $this->translator->trans('nav_media', [], 'cms_themes'))));
    }

    #[Route(path: '/{id}/thumbnail', name: 'public_cms_media_thumbnail', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function thumbnail(Request $request, int $id): Response
    {
        $site = $this->siteResolver->resolve($request);
        if (null === $site || !$this->featureToggle->isEnabled($site, 'media')) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return $this->maintenancePageResponseFactory->create($maintenance);
        }
        $asset = $this->mediaRepository->findOneBy(['id' => $id, 'site' => $site]);
        $image = $asset instanceof MediaAsset && $this->gallery->isPublicImage($asset) ? $this->thumbnails->image($asset, 'picker') : null;
        if (null === $image) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }

        return $this->thumbnailResponse->create($request, $image);
    }

    /**
     * @return array{active_theme: string, template_key: string, page: array{slug: string, title: string},
     * cms_navigation: list<array{label: string, url: string, external: bool}>, cms_footer_links:
     * list<array{label: string, url: string, external: bool}>, cms_branding: array{logo_path: string,
     * primary_color: string, socials: array<string, string>}}
     */
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
