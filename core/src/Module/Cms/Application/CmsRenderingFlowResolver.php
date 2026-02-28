<?php

declare(strict_types=1);

namespace App\Module\Cms\Application;

use App\Module\Core\Domain\Entity\Site;
use Symfony\Component\HttpFoundation\Request;

final class CmsRenderingFlowResolver
{
    public function __construct(
        private readonly ThemeResolver $themeResolver,
        private readonly CmsMaintenanceService $maintenanceService,
        private readonly CmsMaintenanceWindowProviderInterface $maintenanceWindowProvider,
    ) {
    }

    /**
     * @return array{template_key:string,maintenance:array{active:bool,scope:string|null,message:string,graphic_path:string,starts_at:?\DateTimeImmutable,ends_at:?\DateTimeImmutable}}
     */
    public function resolve(Request $request, Site $site): array
    {
        $templateKey = $this->themeResolver->resolveThemeKey($site);
        $maintenance = $this->maintenanceService->resolve($request, $site);
        if ($maintenance['active']) {
            return ['template_key' => $templateKey, 'maintenance' => $maintenance];
        }

        $siteId = $site->getId();
        if ($siteId !== null) {
            $now = new \DateTimeImmutable();
            $activeWindows = $this->maintenanceWindowProvider->findCurrentPublicBySite($siteId, $now);
            if ($activeWindows !== []) {
                $window = $activeWindows[0];
                return [
                    'template_key' => $templateKey,
                    'maintenance' => [
                        'active' => true,
                        'scope' => 'window',
                        'message' => $window->getMessage() ?? $window->getTitle(),
                        'graphic_path' => '',
                        'starts_at' => $window->getStartAt(),
                        'ends_at' => $window->getEndAt(),
                    ],
                ];
            }
        }

        return ['template_key' => $templateKey, 'maintenance' => $maintenance];
    }
}
