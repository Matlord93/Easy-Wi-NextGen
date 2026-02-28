<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsFeatureToggle;
use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\UI\Http\MaintenancePageResponseFactory;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\CmsEvent;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\CmsEventRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/events')]
final class PublicCmsEventsController
{
    public function __construct(
        private readonly CmsEventRepository $eventRepository,
        private readonly SiteResolver $siteResolver,
        private readonly CmsFeatureToggle $featureToggle,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly MaintenancePageResponseFactory $maintenancePageResponseFactory,
        private readonly ThemeResolver $themeResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'public_cms_events_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null || !$this->featureToggle->isEnabled($site, 'events')) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return $this->maintenancePageResponseFactory->create($maintenance);
        }

        return new Response($this->twig->render('public/events/index.html.twig', [
            'events' => $this->normalize($this->eventRepository->findPublishedBySite($site)),
        ] + $this->themeContext($site, 'events', 'Events')));
    }

    #[Route(path: '/{slug}', name: 'public_cms_events_show', methods: ['GET'])]
    public function show(Request $request, string $slug): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null || !$this->featureToggle->isEnabled($site, 'events')) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return $this->maintenancePageResponseFactory->create($maintenance);
        }

        $event = $this->eventRepository->findOneBySiteAndSlug($site, $slug, true);
        if (!$event instanceof CmsEvent) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        return new Response($this->twig->render('public/events/show.html.twig', [
            'event' => $this->normalizeOne($event),
        ] + $this->themeContext($site, 'events', $event->getTitle())));
    }

    /** @param list<CmsEvent> $events @return list<array<string,mixed>> */
    private function normalize(array $events): array
    {
        return array_map(fn (CmsEvent $event): array => $this->normalizeOne($event), $events);
    }

    /** @return array<string,mixed> */
    private function normalizeOne(CmsEvent $event): array
    {
        return [
            'title' => $event->getTitle(),
            'slug' => $event->getSlug(),
            'description' => $event->getDescription(),
            'location' => $event->getLocation(),
            'status' => $event->getStatus(),
            'cover_image_path' => $event->getCoverImagePath(),
            'start_at' => $event->getStartAt(),
            'end_at' => $event->getEndAt(),
        ];
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
