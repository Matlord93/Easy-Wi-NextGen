<?php

declare(strict_types=1);

namespace App\Module\Musicbot\UI\Controller\Api;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Musicbot\Application\MusicbotRoleService;
use App\Module\Musicbot\Domain\Entity\MusicbotRole;
use App\Module\Musicbot\Domain\Entity\MusicbotRoleAssignment;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Enum\MusicbotPermission;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleChannel;
use App\Module\Musicbot\Domain\Enum\MusicbotRoleSubjectType;
use App\Repository\MusicbotInstanceRepository;
use App\Repository\MusicbotRoleAssignmentRepository;
use App\Repository\MusicbotRoleRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class MusicbotRoleApiController
{
    public function __construct(
        private readonly MusicbotRoleService $roleService,
        private readonly MusicbotInstanceRepository $instanceRepository,
        private readonly MusicbotRoleRepository $roleRepository,
        private readonly MusicbotRoleAssignmentRepository $assignmentRepository,
    ) {
    }

    // -------------------------------------------------------------------------
    // Role endpoints
    // -------------------------------------------------------------------------

    #[Route(
        path: '/api/v1/customer/musicbots/{id}/roles',
        name: 'api_v1_musicbot_roles_index',
        requirements: ['id' => '\\d+'],
        methods: ['GET'],
    )]
    public function index(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstance($id, $customer);

        $roles = $this->roleService->getRolesForInstance($instance);

        return new JsonResponse([
            'data' => array_map(fn (MusicbotRole $r): array => $this->normalizeRole($r), $roles),
        ]);
    }

    #[Route(
        path: '/api/v1/customer/musicbots/{id}/roles',
        name: 'api_v1_musicbot_roles_create',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function create(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstance($id, $customer);
        $payload  = $request->toArray();

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new BadRequestHttpException('Field "name" is required.');
        }

        $role = $this->roleService->createRole(
            instance:    $instance,
            name:        $name,
            permissions: $this->extractPermissions($payload),
            channels:    $this->extractChannels($payload),
            description: isset($payload['description']) ? (string) $payload['description'] : null,
            isDefault:   (bool) ($payload['is_default'] ?? false),
            position:    (int) ($payload['position'] ?? 0),
        );

        return new JsonResponse(['data' => $this->normalizeRole($role)], JsonResponse::HTTP_CREATED);
    }

    #[Route(
        path: '/api/v1/customer/musicbots/{id}/roles/{roleId}',
        name: 'api_v1_musicbot_roles_show',
        requirements: ['id' => '\\d+', 'roleId' => '\\d+'],
        methods: ['GET'],
    )]
    public function show(Request $request, int $id, int $roleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstance($id, $customer);
        $role     = $this->findRole($roleId, $instance);

        return new JsonResponse(['data' => $this->normalizeRole($role)]);
    }

    #[Route(
        path: '/api/v1/customer/musicbots/{id}/roles/{roleId}',
        name: 'api_v1_musicbot_roles_update',
        requirements: ['id' => '\\d+', 'roleId' => '\\d+'],
        methods: ['PUT', 'PATCH'],
    )]
    public function update(Request $request, int $id, int $roleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstance($id, $customer);
        $role     = $this->findRole($roleId, $instance);
        $payload  = $request->toArray();

        $name = trim((string) ($payload['name'] ?? $role->getName()));
        if ($name === '') {
            throw new BadRequestHttpException('Field "name" must not be empty.');
        }

        $this->roleService->updateRole(
            role:        $role,
            name:        $name,
            permissions: array_key_exists('permissions', $payload) ? $this->extractPermissions($payload) : $role->getPermissions(),
            channels:    array_key_exists('channels', $payload) ? $this->extractChannels($payload) : $role->getChannels(),
            description: array_key_exists('description', $payload) ? ($payload['description'] !== null ? (string) $payload['description'] : null) : $role->getDescription(),
            isDefault:   array_key_exists('is_default', $payload) ? (bool) $payload['is_default'] : $role->isDefault(),
            position:    array_key_exists('position', $payload) ? (int) $payload['position'] : $role->getPosition(),
        );

        return new JsonResponse(['data' => $this->normalizeRole($role)]);
    }

    #[Route(
        path: '/api/v1/customer/musicbots/{id}/roles/{roleId}',
        name: 'api_v1_musicbot_roles_delete',
        requirements: ['id' => '\\d+', 'roleId' => '\\d+'],
        methods: ['DELETE'],
    )]
    public function delete(Request $request, int $id, int $roleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstance($id, $customer);
        $role     = $this->findRole($roleId, $instance);

        $this->roleService->deleteRole($role);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Assignment endpoints
    // -------------------------------------------------------------------------

    #[Route(
        path: '/api/v1/customer/musicbots/{id}/roles/{roleId}/assignments',
        name: 'api_v1_musicbot_role_assignments_index',
        requirements: ['id' => '\\d+', 'roleId' => '\\d+'],
        methods: ['GET'],
    )]
    public function assignmentsIndex(Request $request, int $id, int $roleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstance($id, $customer);
        $role     = $this->findRole($roleId, $instance);

        $assignments = $this->roleService->getAssignmentsForRole($role);

        return new JsonResponse([
            'data' => array_map(fn (MusicbotRoleAssignment $a): array => $this->normalizeAssignment($a), $assignments),
        ]);
    }

    #[Route(
        path: '/api/v1/customer/musicbots/{id}/roles/{roleId}/assignments',
        name: 'api_v1_musicbot_role_assignments_create',
        requirements: ['id' => '\\d+', 'roleId' => '\\d+'],
        methods: ['POST'],
    )]
    public function assignmentsCreate(Request $request, int $id, int $roleId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstance($id, $customer);
        $role     = $this->findRole($roleId, $instance);
        $payload  = $request->toArray();

        $subjectTypeValue = trim((string) ($payload['subject_type'] ?? ''));
        $subjectId        = trim((string) ($payload['subject_id'] ?? ''));

        if ($subjectTypeValue === '' || $subjectId === '') {
            throw new BadRequestHttpException('Fields "subject_type" and "subject_id" are required.');
        }

        $subjectType = MusicbotRoleSubjectType::tryFrom($subjectTypeValue)
            ?? throw new BadRequestHttpException(
                sprintf('Invalid subject_type "%s". Valid values: %s.', $subjectTypeValue, implode(', ', array_column(MusicbotRoleSubjectType::cases(), 'value'))),
            );

        $assignment = $this->roleService->assign($role, $subjectType, $subjectId, $customer);

        return new JsonResponse(['data' => $this->normalizeAssignment($assignment)], JsonResponse::HTTP_CREATED);
    }

    #[Route(
        path: '/api/v1/customer/musicbots/{id}/roles/{roleId}/assignments/{assignmentId}',
        name: 'api_v1_musicbot_role_assignments_delete',
        requirements: ['id' => '\\d+', 'roleId' => '\\d+', 'assignmentId' => '\\d+'],
        methods: ['DELETE'],
    )]
    public function assignmentsDelete(Request $request, int $id, int $roleId, int $assignmentId): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstance($id, $customer);
        $role     = $this->findRole($roleId, $instance);

        $assignment = $this->assignmentRepository->findOneForRole($assignmentId, $role)
            ?? throw new NotFoundHttpException('Assignment not found.');

        $this->roleService->revoke($assignment);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    // -------------------------------------------------------------------------
    // Effective permissions query
    // -------------------------------------------------------------------------

    #[Route(
        path: '/api/v1/customer/musicbots/{id}/roles/check',
        name: 'api_v1_musicbot_roles_check',
        requirements: ['id' => '\\d+'],
        methods: ['POST'],
    )]
    public function checkPermission(Request $request, int $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $instance = $this->findInstance($id, $customer);
        $payload  = $request->toArray();

        $subjectTypeValue = trim((string) ($payload['subject_type'] ?? ''));
        $subjectId        = trim((string) ($payload['subject_id'] ?? ''));
        $channelValue     = trim((string) ($payload['channel'] ?? ''));

        if ($subjectTypeValue === '' || $subjectId === '' || $channelValue === '') {
            throw new BadRequestHttpException('Fields "subject_type", "subject_id", and "channel" are required.');
        }

        $subjectType = MusicbotRoleSubjectType::tryFrom($subjectTypeValue)
            ?? throw new BadRequestHttpException('Invalid subject_type.');

        $channel = MusicbotRoleChannel::tryFrom($channelValue)
            ?? throw new BadRequestHttpException(
                sprintf('Invalid channel "%s". Valid values: %s.', $channelValue, implode(', ', array_column(MusicbotRoleChannel::cases(), 'value'))),
            );

        $effective = $this->roleService->getEffectivePermissions($instance, $subjectType, $subjectId, $channel);

        $permissionQuery = trim((string) ($payload['permission'] ?? ''));
        if ($permissionQuery !== '') {
            $perm = MusicbotPermission::tryFrom($permissionQuery)
                ?? throw new BadRequestHttpException(sprintf('Unknown permission "%s".', $permissionQuery));

            return new JsonResponse([
                'subject_type' => $subjectType->value,
                'subject_id'   => $subjectId,
                'channel'      => $channel->value,
                'permission'   => $perm->value,
                'granted'      => in_array($perm->value, $effective, true),
            ]);
        }

        return new JsonResponse([
            'subject_type'        => $subjectType->value,
            'subject_id'          => $subjectId,
            'channel'             => $channel->value,
            'effective_permissions' => $effective,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('api', 'Unauthorized.');
        }

        return $actor;
    }

    private function findInstance(int $id, User $customer): MusicbotInstance
    {
        $instance = $this->instanceRepository->findOneForCustomer($id, $customer);
        if (!$instance instanceof MusicbotInstance) {
            throw new NotFoundHttpException('Musicbot not found.');
        }

        return $instance;
    }

    private function findRole(int $roleId, MusicbotInstance $instance): MusicbotRole
    {
        $role = $this->roleRepository->findOneForInstance($roleId, $instance);
        if (!$role instanceof MusicbotRole) {
            throw new NotFoundHttpException('Role not found.');
        }

        return $role;
    }

    /** @param array<string, mixed> $payload */
    private function extractPermissions(array $payload): array
    {
        $raw   = $payload['permissions'] ?? [];
        $valid = array_map(static fn (MusicbotPermission $p): string => $p->value, MusicbotPermission::cases());

        if (!is_array($raw)) {
            throw new BadRequestHttpException('"permissions" must be an array.');
        }

        $unknown = array_diff($raw, $valid);
        if ($unknown !== []) {
            throw new BadRequestHttpException(sprintf('Unknown permissions: %s.', implode(', ', $unknown)));
        }

        return array_values((array) $raw);
    }

    /** @param array<string, mixed> $payload */
    private function extractChannels(array $payload): array
    {
        $raw   = $payload['channels'] ?? [];
        $valid = array_map(static fn (MusicbotRoleChannel $c): string => $c->value, MusicbotRoleChannel::cases());

        if (!is_array($raw)) {
            throw new BadRequestHttpException('"channels" must be an array.');
        }

        $unknown = array_diff($raw, $valid);
        if ($unknown !== []) {
            throw new BadRequestHttpException(sprintf('Unknown channels: %s.', implode(', ', $unknown)));
        }

        return array_values((array) $raw);
    }

    /** @return array<string, mixed> */
    private function normalizeRole(MusicbotRole $role): array
    {
        return [
            'id'          => $role->getId(),
            'instance_id' => $role->getInstance()->getId(),
            'name'        => $role->getName(),
            'description' => $role->getDescription(),
            'permissions' => $role->getPermissions(),
            'channels'    => $role->getChannels(),
            'is_default'  => $role->isDefault(),
            'position'    => $role->getPosition(),
            'created_at'  => $role->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'  => $role->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeAssignment(MusicbotRoleAssignment $a): array
    {
        return [
            'id'           => $a->getId(),
            'role_id'      => $a->getRole()->getId(),
            'subject_type' => $a->getSubjectType()->value,
            'subject_id'   => $a->getSubjectId(),
            'granted_by'   => $a->getGrantedBy()?->getId(),
            'created_at'   => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
