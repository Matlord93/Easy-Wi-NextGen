<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Api;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotPermissionService;
use App\Module\Musicbot\Application\MusicbotQuotaService;
use App\Module\Musicbot\Application\MusicbotRadioCatalogService;
use App\Module\Musicbot\Application\MusicbotRadioService;
use App\Module\Musicbot\Application\MusicbotRuntimeEventService;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotRadioStation;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Exception\MusicbotPermissionDeniedException;
use App\Module\Musicbot\Domain\Exception\MusicbotQuotaExceededException;
use App\Repository\MusicbotInstanceRepository;
use App\Repository\MusicbotRadioStationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class MusicbotRadioApiController
{
    public function __construct(
        private readonly MusicbotRadioService $radioService,
        private readonly MusicbotRadioCatalogService $catalogService,
        private readonly MusicbotRadioStationRepository $stationRepository,
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly MusicbotQuotaService $quotaService,
        private readonly MusicbotPermissionService $permissionService,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // =========================================================================
    // Catalog / search
    // =========================================================================

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/catalog', name: 'api_v1_customer_radio_catalog', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function catalog(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $filters = $this->extractFilters($request);

        try {
            $result = $this->catalogService->search($customer, $filters);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => $result]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/search', name: 'api_v1_customer_radio_search', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function search(Request $request, int $id): JsonResponse
    {
        return $this->catalog($request, $id);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/popular', name: 'api_v1_customer_radio_popular', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function popular(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);

        try {
            $data = $this->catalogService->getPopular($customer);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => $data]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/newest', name: 'api_v1_customer_radio_newest', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function newest(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);

        try {
            $data = $this->catalogService->getNewest($customer);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => $data]);
    }

    // =========================================================================
    // Customer private stations
    // =========================================================================

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/list', name: 'api_v1_customer_radio_list', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function list(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);

        try {
            $stations = $this->radioService->listForCustomer($customer);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        $instanceStations = array_filter($stations, static fn (MusicbotRadioStation $s): bool => $s->getInstance()?->getId() === $instance->getId());

        return new JsonResponse([
            'data' => array_values(array_map(fn (MusicbotRadioStation $s): array => $this->radioService->normalize($s), $instanceStations)),
        ]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/create', name: 'api_v1_customer_radio_create', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function create(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $payload = $request->toArray();

        try {
            $station = $this->radioService->create($customer, $instance, $payload);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();
        $this->auditLogger->log($customer, 'musicbot.radio.created', ['instance_id' => $id, 'station_id' => $station->getId(), 'name' => $station->getName()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->radioService->normalize($station)], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/update/{stationId}', name: 'api_v1_customer_radio_update', requirements: ['id' => '\\d+', 'stationId' => '\\d+'], methods: ['PATCH'])]
    public function update(Request $request, int $id, int $stationId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $station = $this->findOwnedStation($stationId, $customer);
        $payload = $request->toArray();

        try {
            $this->radioService->update($station, $payload);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();
        $this->auditLogger->log($customer, 'musicbot.radio.updated', ['instance_id' => $id, 'station_id' => $station->getId()]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->radioService->normalize($station)]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/delete/{stationId}', name: 'api_v1_customer_radio_delete', requirements: ['id' => '\\d+', 'stationId' => '\\d+'], methods: ['DELETE'])]
    public function delete(Request $request, int $id, int $stationId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $station = $this->findOwnedStation($stationId, $customer);

        $this->radioService->delete($station);
        $this->entityManager->flush();
        $this->auditLogger->log($customer, 'musicbot.radio.deleted', ['instance_id' => $id, 'station_id' => $stationId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    // =========================================================================
    // Favorites
    // =========================================================================

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/favorites', name: 'api_v1_customer_radio_favorites', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function favorites(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);

        try {
            $data = $this->catalogService->getFavorites($customer);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => $data]);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/favorites/{stationId}', name: 'api_v1_customer_radio_favorites_add', requirements: ['id' => '\\d+', 'stationId' => '\\d+'], methods: ['POST'])]
    public function addFavorite(Request $request, int $id, int $stationId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $station = $this->findAccessibleStation($stationId, $customer);

        try {
            $this->catalogService->addFavorite($customer, $station);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        $this->entityManager->flush();

        return new JsonResponse(['data' => ['favorited' => true, 'station_id' => $stationId]], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/favorites/{stationId}', name: 'api_v1_customer_radio_favorites_remove', requirements: ['id' => '\\d+', 'stationId' => '\\d+'], methods: ['DELETE'])]
    public function removeFavorite(Request $request, int $id, int $stationId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $station = $this->findAccessibleStation($stationId, $customer);

        try {
            $this->catalogService->removeFavorite($customer, $station);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        $this->entityManager->flush();

        return new JsonResponse(['data' => ['favorited' => false, 'station_id' => $stationId]]);
    }

    // =========================================================================
    // History
    // =========================================================================

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/history', name: 'api_v1_customer_radio_history', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function history(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);

        try {
            $data = $this->catalogService->getHistory($customer);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        }

        return new JsonResponse(['data' => $data]);
    }

    // =========================================================================
    // Playback
    // =========================================================================

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/play/{stationId}', name: 'api_v1_customer_radio_play', requirements: ['id' => '\\d+', 'stationId' => '\\d+'], methods: ['POST'])]
    public function play(Request $request, int $id, int $stationId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $station = $this->findAccessibleStation($stationId, $customer);

        try {
            $result = $this->radioService->playNow($customer, $instance, $station);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();
        $this->runtimeEventService->record($instance, 'radio.play', 'info', sprintf('Radio station "%s" played.', $station->getName()), ['station_id' => $stationId]);
        $this->auditLogger->log($customer, 'musicbot.radio.play', ['instance_id' => $id, 'station_id' => $stationId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $result], JsonResponse::HTTP_ACCEPTED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/queue/{stationId}', name: 'api_v1_customer_radio_queue', requirements: ['id' => '\\d+', 'stationId' => '\\d+'], methods: ['POST'])]
    public function queue(Request $request, int $id, int $stationId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $station = $this->findAccessibleStation($stationId, $customer);

        try {
            $result = $this->radioService->addToQueue($customer, $instance, $station);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();
        $this->runtimeEventService->record($instance, 'radio.queue', 'info', sprintf('Radio station "%s" added to queue.', $station->getName()), ['station_id' => $stationId]);
        $this->auditLogger->log($customer, 'musicbot.radio.queue', ['instance_id' => $id, 'station_id' => $stationId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $result], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/playlist/{stationId}', name: 'api_v1_customer_radio_playlist', requirements: ['id' => '\\d+', 'stationId' => '\\d+'], methods: ['POST'])]
    public function playlist(Request $request, int $id, int $stationId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $station = $this->findAccessibleStation($stationId, $customer);
        $payload = $request->toArray();
        $playlistId = (int) ($payload['playlist_id'] ?? 0);

        if ($playlistId <= 0) {
            return $this->error('playlist_id is required.', JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->radioService->addToPlaylist($customer, $instance, $station, $playlistId);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();
        $this->runtimeEventService->record($instance, 'radio.playlist', 'info', sprintf('Radio station "%s" added to playlist.', $station->getName()), ['station_id' => $stationId, 'playlist_id' => $playlistId]);
        $this->auditLogger->log($customer, 'musicbot.radio.playlist', ['instance_id' => $id, 'station_id' => $stationId, 'playlist_id' => $playlistId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $result], JsonResponse::HTTP_CREATED);
    }

    // =========================================================================
    // URL resolver
    // =========================================================================

    #[Route(path: '/api/v1/customer/musicbots/{id}/radio/resolve', name: 'api_v1_customer_radio_resolve', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function resolve(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findCustomerInstance($id, $customer);
        $this->assertPerm($customer, $instance, MusicbotPermission::WebradioManage);
        $url = trim((string) ($request->toArray()['url'] ?? ''));

        if ($url === '') {
            return $this->error('url is required.', JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->radioService->resolveUrl($customer, $url);
        } catch (MusicbotQuotaExceededException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $result]);
    }

    // =========================================================================
    // Admin catalog management
    // =========================================================================

    #[Route(path: '/api/v1/admin/radio/catalog', name: 'api_v1_admin_radio_catalog', methods: ['GET'])]
    public function adminCatalog(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        return new JsonResponse(['data' => $this->catalogService->getAllGlobal(true)]);
    }

    #[Route(path: '/api/v1/admin/radio/pending', name: 'api_v1_admin_radio_pending', methods: ['GET'])]
    public function adminPending(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        return new JsonResponse(['data' => $this->catalogService->getPendingCustomerStations()]);
    }

    #[Route(path: '/api/v1/admin/radio/promote/{stationId}', name: 'api_v1_admin_radio_promote', requirements: ['stationId' => '\\d+'], methods: ['POST'])]
    public function adminPromote(Request $request, int $stationId): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $station = $this->stationRepository->find($stationId);
        if ($station === null) {
            return $this->error('Station not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $this->catalogService->promoteToGlobal($station);
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.radio.admin.promoted', ['station_id' => $stationId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->catalogService->normalize($station)]);
    }

    #[Route(path: '/api/v1/admin/radio/deactivate/{stationId}', name: 'api_v1_admin_radio_deactivate', requirements: ['stationId' => '\\d+'], methods: ['POST'])]
    public function adminDeactivate(Request $request, int $stationId): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $station = $this->stationRepository->find($stationId);
        if ($station === null) {
            return $this->error('Station not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $this->catalogService->markInactive($station);
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.radio.admin.deactivated', ['station_id' => $stationId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['station_id' => $stationId, 'is_active' => false]]);
    }

    #[Route(path: '/api/v1/admin/radio/activate/{stationId}', name: 'api_v1_admin_radio_activate', requirements: ['stationId' => '\\d+'], methods: ['POST'])]
    public function adminActivate(Request $request, int $stationId): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $station = $this->stationRepository->find($stationId);
        if ($station === null) {
            return $this->error('Station not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $this->catalogService->markActive($station);
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.radio.admin.activated', ['station_id' => $stationId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['station_id' => $stationId, 'is_active' => true]]);
    }

    #[Route(path: '/api/v1/admin/radio/update/{stationId}', name: 'api_v1_admin_radio_update', requirements: ['stationId' => '\\d+'], methods: ['PATCH'])]
    public function adminUpdate(Request $request, int $stationId): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $station = $this->stationRepository->find($stationId);
        if ($station === null) {
            return $this->error('Station not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $this->radioService->update($station, $request->toArray());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.radio.admin.updated', ['station_id' => $stationId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => $this->catalogService->normalize($station)]);
    }

    #[Route(path: '/api/v1/admin/radio/delete/{stationId}', name: 'api_v1_admin_radio_delete', requirements: ['stationId' => '\\d+'], methods: ['DELETE'])]
    public function adminDelete(Request $request, int $stationId): JsonResponse
    {
        $actor = $this->requireAdmin($request);
        $station = $this->stationRepository->find($stationId);
        if ($station === null) {
            return $this->error('Station not found.', JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($station);
        $this->entityManager->flush();
        $this->auditLogger->log($actor, 'musicbot.radio.admin.deleted', ['station_id' => $stationId]);
        $this->entityManager->flush();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function assertPerm(User $customer, MusicbotInstance $instance, MusicbotPermission $permission): void
    {
        try {
            $this->permissionService->assertActionAllowed($customer, $instance, $permission);
        } catch (MusicbotPermissionDeniedException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('api', 'Unauthorized.');
        }

        try {
            $this->quotaService->assertApiAllowed($actor);
        } catch (MusicbotQuotaExceededException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return $actor;
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new AccessDeniedHttpException('Forbidden.');
        }

        return $actor;
    }

    private function findCustomerInstance(int $id, User $customer): MusicbotInstance
    {
        $instance = $this->instanceRepository->findOneForCustomer($id, $customer);
        if (!$instance instanceof MusicbotInstance) {
            throw new NotFoundHttpException('Musicbot not found.');
        }

        return $instance;
    }

    private function findAccessibleStation(int $stationId, User $customer): MusicbotRadioStation
    {
        $station = $this->radioService->findAccessible($stationId, $customer);
        if ($station === null) {
            throw new NotFoundHttpException('Radio station not found.');
        }

        return $station;
    }

    private function findOwnedStation(int $stationId, User $customer): MusicbotRadioStation
    {
        $station = $this->stationRepository->findOneForCustomer($stationId, $customer);
        if ($station === null) {
            throw new NotFoundHttpException('Radio station not found or access denied.');
        }

        return $station;
    }

    /** @return array<string, mixed> */
    private function extractFilters(Request $request): array
    {
        return array_filter([
            'query'       => $request->query->get('q') ?? $request->query->get('query'),
            'genre'       => $request->query->get('genre'),
            'country'     => $request->query->get('country'),
            'language'    => $request->query->get('language'),
            'format'      => $request->query->get('format'),
            'min_bitrate' => $request->query->get('min_bitrate') ? (int) $request->query->get('min_bitrate') : null,
            'max_bitrate' => $request->query->get('max_bitrate') ? (int) $request->query->get('max_bitrate') : null,
            'limit'       => $request->query->get('limit') ? (int) $request->query->get('limit') : 50,
            'offset'      => $request->query->get('offset') ? (int) $request->query->get('offset') : 0,
        ], static fn ($v): bool => $v !== null && $v !== '');
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
