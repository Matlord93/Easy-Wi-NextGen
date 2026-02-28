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
use App\Module\Core\Domain\Entity\TeamMember;
use App\Repository\TeamMemberRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/teams')]
final class PublicCmsTeamController
{
    public function __construct(
        private readonly TeamMemberRepository $memberRepository,
        private readonly SiteResolver $siteResolver,
        private readonly CmsFeatureToggle $featureToggle,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly MaintenancePageResponseFactory $maintenancePageResponseFactory,
        private readonly ThemeResolver $themeResolver,
        private readonly CmsSettingsProvider $settingsProvider,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'public_cms_team_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $site = $this->siteResolver->resolve($request);
        if ($site === null || !$this->featureToggle->isEnabled($site, 'team')) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return $this->maintenancePageResponseFactory->create($maintenance);
        }

        $members = $this->memberRepository->findActiveBySite($site);

        return new Response($this->twig->render('public/team/index.html.twig', [
            'members' => array_map(static fn (TeamMember $member): array => [
                'name' => $member->getName(),
                'role_title' => $member->getRoleTitle(),
                'bio' => $member->getBio(),
                'avatar_path' => $member->getAvatarPath(),
                'socials_json' => $member->getSocialsJson(),
            ], $members),
        ] + $this->themeContext($site, 'teams', 'Team')));
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
