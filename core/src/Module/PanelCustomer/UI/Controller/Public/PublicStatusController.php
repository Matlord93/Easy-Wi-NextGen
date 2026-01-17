<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Module\Core\Domain\Entity\Incident;
use App\Module\Core\Domain\Entity\IncidentUpdate;
use App\Module\Core\Domain\Entity\MaintenanceWindow;
use App\Module\Core\Domain\Entity\StatusComponent;
use App\Repository\IncidentRepository;
use App\Repository\IncidentUpdateRepository;
use App\Repository\MaintenanceWindowRepository;
use App\Repository\StatusComponentRepository;
use App\Module\Core\Application\SiteResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Twig\Environment;

final class PublicStatusController
{
    public function __construct(
        private readonly StatusComponentRepository $componentRepository,
        private readonly MaintenanceWindowRepository $maintenanceRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly IncidentUpdateRepository $updateRepository,
        private readonly SiteResolver $siteResolver,
        #[Autowire(service: 'limiter.public_status')]
        private readonly RateLimiterFactory $statusLimiter,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/status', name: 'public_status', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $limiter = $this->statusLimiter->create($request->getClientIp() ?? 'public');
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $response = new Response('Too Many Requests.', Response::HTTP_TOO_MANY_REQUESTS);
            $retryAfter = $limit->getRetryAfter();
            if ($retryAfter !== null) {
                $seconds = max(1, $retryAfter->getTimestamp() - time());
                $response->headers->set('Retry-After', (string) $seconds);
            }

            return $response;
        }

        $site = $this->siteResolver->resolve($request);
        if ($site === null) {
            return new Response('Site not found.', Response::HTTP_NOT_FOUND);
        }

        $now = new \DateTimeImmutable();

        $components = $this->componentRepository->findVisiblePublicBySite($site->getId() ?? 0);
        $incidents = $this->incidentRepository->findCurrentPublicBySite($site->getId() ?? 0, $now);
        $upcomingMaintenance = $this->maintenanceRepository->findUpcomingPublicBySite($site->getId() ?? 0, $now);
        $currentMaintenance = $this->maintenanceRepository->findCurrentPublicBySite($site->getId() ?? 0, $now);

        $response = new Response($this->twig->render('public/status/index.html.twig', [
            'components' => $this->normalizeComponents($components),
            'incidents' => $this->normalizeIncidents($incidents),
            'incidentUpdates' => $this->normalizeIncidentUpdates($incidents),
            'upcomingMaintenance' => $this->normalizeMaintenance($upcomingMaintenance),
            'currentMaintenance' => $this->normalizeMaintenance($currentMaintenance),
            'activeNav' => 'status',
        ]));

        $response->setPublic();
        $response->setMaxAge(30);
        $response->headers->addCacheControlDirective('s-maxage', '30');
        $response->headers->addCacheControlDirective('stale-while-revalidate', '30');

        return $response;
    }

    /**
     * @param StatusComponent[] $components
     */
    private function normalizeComponents(array $components): array
    {
        return array_map(static function (StatusComponent $component): array {
            return [
                'id' => $component->getId(),
                'name' => $component->getName(),
                'type' => $component->getType(),
                'status' => $component->getStatus(),
                'last_checked_at' => $component->getLastCheckedAt(),
            ];
        }, $components);
    }

    /**
     * @param Incident[] $incidents
     */
    private function normalizeIncidents(array $incidents): array
    {
        return array_map(static function (Incident $incident): array {
            return [
                'id' => $incident->getId(),
                'title' => $incident->getTitle(),
                'status' => $incident->getStatus(),
                'message' => $incident->getMessage(),
                'started_at' => $incident->getStartedAt(),
                'resolved_at' => $incident->getResolvedAt(),
                'components' => array_map(static fn (StatusComponent $component): array => [
                    'id' => $component->getId(),
                    'name' => $component->getName(),
                ], array_filter($incident->getAffectedComponents()->toArray(), static fn (StatusComponent $component): bool => $component->isVisiblePublic())),
            ];
        }, $incidents);
    }

    /**
     * @param Incident[] $incidents
     */
    private function normalizeIncidentUpdates(array $incidents): array
    {
        $updates = [];
        foreach ($incidents as $incident) {
            $updates[$incident->getId() ?? 0] = $this->normalizeUpdates(
                $this->updateRepository->findByIncident($incident)
            );
        }

        return $updates;
    }

    /**
     * @param MaintenanceWindow[] $windows
     */
    private function normalizeMaintenance(array $windows): array
    {
        return array_map(static function (MaintenanceWindow $window): array {
            return [
                'id' => $window->getId(),
                'title' => $window->getTitle(),
                'message' => $window->getMessage(),
                'start_at' => $window->getStartAt(),
                'end_at' => $window->getEndAt(),
                'components' => array_map(static fn (StatusComponent $component): array => [
                    'id' => $component->getId(),
                    'name' => $component->getName(),
                ], array_filter($window->getAffectedComponents()->toArray(), static fn (StatusComponent $component): bool => $component->isVisiblePublic())),
            ];
        }, $windows);
    }

    /**
     * @param IncidentUpdate[] $updates
     */
    private function normalizeUpdates(array $updates): array
    {
        return array_map(static function (IncidentUpdate $update): array {
            return [
                'id' => $update->getId(),
                'status' => $update->getStatus(),
                'message' => $update->getMessage(),
                'created_at' => $update->getCreatedAt(),
            ];
        }, $updates);
    }
}
