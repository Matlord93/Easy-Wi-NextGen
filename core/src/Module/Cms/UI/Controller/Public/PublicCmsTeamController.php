<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsFeatureToggle;
use App\Module\Cms\Application\CmsMaintenanceService;
use App\Module\Cms\Application\CmsSettingsProvider;
use App\Module\Cms\Application\ThemeResolver;
use App\Module\Cms\UI\Http\MaintenancePageResponseFactory;
use App\Module\Core\Application\SiteResolver;
use App\Module\Core\Domain\Entity\Site;
use App\Repository\TeamGroupRepository;
use App\Repository\TeamMemberRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/teams')]
final class PublicCmsTeamController
{
    public function __construct(private readonly TeamMemberRepository $memberRepository, private readonly TeamGroupRepository $teamGroupRepository, private readonly SiteResolver $siteResolver, private readonly CmsFeatureToggle $featureToggle, private readonly CmsMaintenanceService $maintenanceService, private readonly MaintenancePageResponseFactory $maintenancePageResponseFactory, private readonly ThemeResolver $themeResolver, private readonly CmsSettingsProvider $settingsProvider, private readonly Environment $twig) {}

    #[Route(path: '', name: 'public_cms_team_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null || !$this->featureToggle->isEnabled($site, 'team')) return new Response('Not found.', 404);
        $groups = $this->teamGroupRepository->findBySite($site);
        return new Response($this->twig->render('public/team/index.html.twig', ['team_groups' => $groups] + $this->themeContext($site, 'teams', 'Team')));
    }

    #[Route(path: '/{slug}', name: 'public_cms_team_show', methods: ['GET'])]
    public function show(Request $request, string $slug): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null || !$this->featureToggle->isEnabled($site, 'team')) return new Response('Not found.', 404);
        $group = $this->teamGroupRepository->findOneBySiteAndSlug($site, $slug);
        if ($group === null) return new Response('Not found.', 404);
        $members = array_values(array_filter($this->memberRepository->findActiveBySite($site), fn($m) => $m->getTeamName() === $group->getName()));
        return new Response($this->twig->render('public/team/show.html.twig', ['group' => $group, 'members' => $members] + $this->themeContext($site, 'teams', $group->getName())));
    }

    private function themeContext(Site $site, string $slug, string $title): array { $templateKey = $this->themeResolver->resolveThemeKey($site); return ['active_theme'=>$templateKey,'template_key'=>$templateKey,'page'=>['slug'=>$slug,'title'=>$title],'cms_navigation'=>$this->settingsProvider->getNavigationLinks($site),'cms_footer_links'=>$this->settingsProvider->getFooterLinks($site),'cms_branding'=>$this->settingsProvider->getBranding($site)]; }
}
