<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Application\EncryptionService;
use App\Module\Core\Domain\Entity\BackupTarget;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\BackupDestinationType;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\BackupTargetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class BackupTargetApiController
{
    private const CREDENTIAL_KEYS = ['username', 'password', 'token'];

    public function __construct(
        private readonly BackupTargetRepository $backupTargetRepository,
        private readonly UserRepository $userRepository,
        private readonly EncryptionService $encryptionService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route(path: '/api/backup-targets', name: 'backup_targets_list', methods: ['GET'])]
    #[Route(path: '/api/v1/customer/backup-targets', name: 'backup_targets_list_v1', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);

        $targets = $actor->isAdmin()
            ? $this->backupTargetRepository->findBy([], ['updatedAt' => 'DESC'])
            : $this->backupTargetRepository->findByCustomer($actor);

        return new JsonResponse([
            'backup_targets' => array_map(fn (BackupTarget $target) => $this->normalizeTarget($target), $targets),
        ]);
    }

    #[Route(path: '/api/backup-targets', name: 'backup_targets_create', methods: ['POST'])]
    #[Route(path: '/api/v1/customer/backup-targets', name: 'backup_targets_create_v1', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->requireUser($request);
        $payload = $this->parseJsonPayload($request);

        $validation = $this->validateTargetPayload($actor, $payload, true);
        if ($validation['error'] instanceof JsonResponse) {
            return $validation['error'];
        }

        $target = new BackupTarget(
            $validation['customer'],
            $validation['type'],
            $validation['label'],
            $validation['config'],
            $validation['encrypted_credentials'],
        );

        $this->entityManager->persist($target);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'backup.target.created', [
            'backup_target_id' => $target->getId(),
            'customer_id' => $target->getCustomer()->getId(),
            'type' => $target->getType()->value,
            'label' => $target->getLabel(),
        ]);

        return new JsonResponse([
            'backup_target' => $this->normalizeTarget($target),
        ], JsonResponse::HTTP_CREATED);
    }

    #[Route(path: '/api/backup-targets/{id}', name: 'backup_targets_update', methods: ['PATCH'])]
    #[Route(path: '/api/v1/customer/backup-targets/{id}', name: 'backup_targets_update_v1', methods: ['PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $target = $this->backupTargetRepository->find($id);
        if ($target === null) {
            return new JsonResponse(['error' => 'Backup target not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessTarget($actor, $target)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $validation = $this->validateTargetPayload($actor, $payload, false, $target);
        if ($validation['error'] instanceof JsonResponse) {
            return $validation['error'];
        }

        if ($validation['type'] !== null) {
            $target->setType($validation['type']);
        }

        if ($validation['label'] !== null) {
            $target->setLabel($validation['label']);
        }

        if ($validation['config'] !== null) {
            $target->setConfig($validation['config']);
        }

        if ($validation['encrypted_credentials'] !== null) {
            $target->setEncryptedCredentials($validation['encrypted_credentials']);
        }

        $this->entityManager->persist($target);
        $this->entityManager->flush();

        $this->auditLogger->log($actor, 'backup.target.updated', [
            'backup_target_id' => $target->getId(),
            'customer_id' => $target->getCustomer()->getId(),
            'type' => $target->getType()->value,
            'label' => $target->getLabel(),
        ]);

        return new JsonResponse([
            'backup_target' => $this->normalizeTarget($target),
        ]);
    }

    #[Route(path: '/api/backup-targets/{id}', name: 'backup_targets_delete', methods: ['DELETE'])]
    #[Route(path: '/api/v1/customer/backup-targets/{id}', name: 'backup_targets_delete_v1', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $actor = $this->requireUser($request);
        $target = $this->backupTargetRepository->find($id);
        if ($target === null) {
            return new JsonResponse(['error' => 'Backup target not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessTarget($actor, $target)) {
            return new JsonResponse(['error' => 'Forbidden.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $this->auditLogger->log($actor, 'backup.target.deleted', [
            'backup_target_id' => $target->getId(),
            'customer_id' => $target->getCustomer()->getId(),
            'type' => $target->getType()->value,
            'label' => $target->getLabel(),
        ]);

        $this->entityManager->remove($target);
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function requireUser(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function parseJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException $exception) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid JSON payload.', $exception);
        }
    }

    private function validateTargetPayload(User $actor, array $payload, bool $isCreate, ?BackupTarget $target = null): array
    {
        $customerId = $payload['customer_id'] ?? null;
        $typeValue = $payload['type'] ?? null;
        $label = $payload['label'] ?? null;

        $customer = $actor;
        if ($actor->isAdmin()) {
            if ($isCreate && !is_numeric($customerId)) {
                return ['error' => new JsonResponse(['error' => 'Customer is required.'], JsonResponse::HTTP_BAD_REQUEST)];
            }

            if (is_numeric($customerId)) {
                $customer = $this->userRepository->find((int) $customerId);
                if ($customer === null || $customer->getType() !== UserType::Customer) {
                    return ['error' => new JsonResponse(['error' => 'Customer not found.'], JsonResponse::HTTP_NOT_FOUND)];
                }
            } elseif ($target !== null) {
                $customer = $target->getCustomer();
            }
        } elseif ($target !== null) {
            $customer = $target->getCustomer();
        }

        $type = null;
        if ($typeValue !== null) {
            $typeValue = strtolower(trim((string) $typeValue));
            $type = BackupDestinationType::tryFrom($typeValue);
            if ($type === null) {
                return ['error' => new JsonResponse(['error' => 'Backup target type is invalid.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
        } elseif ($isCreate) {
            return ['error' => new JsonResponse(['error' => 'Backup target type is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $normalizedLabel = null;
        if ($label !== null) {
            $normalizedLabel = trim((string) $label);
            if ($normalizedLabel === '') {
                return ['error' => new JsonResponse(['error' => 'Label is required.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
        } elseif ($isCreate) {
            return ['error' => new JsonResponse(['error' => 'Label is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $effectiveType = $type ?? $target?->getType();
        if ($effectiveType === null) {
            return ['error' => new JsonResponse(['error' => 'Backup target type is required.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $config = null;
        $typeChanged = $type !== null && $target !== null && $target->getType() !== $type;
        if ($typeChanged && !array_key_exists('config', $payload)) {
            return ['error' => new JsonResponse(['error' => 'Config is required when changing target type.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if (array_key_exists('config', $payload) || $isCreate) {
            $configPayload = $payload['config'] ?? null;
            if (!is_array($configPayload)) {
                return ['error' => new JsonResponse(['error' => 'Config must be an object.'], JsonResponse::HTTP_BAD_REQUEST)];
            }
            $configValidation = $this->normalizeConfig($effectiveType, $configPayload);
            if ($configValidation['error'] instanceof JsonResponse) {
                return ['error' => $configValidation['error']];
            }
            $config = $configValidation['config'];
        }

        $credentialValidation = $this->normalizeCredentialsPayload($effectiveType, $payload, $target, $isCreate);
        if ($credentialValidation['error'] instanceof JsonResponse) {
            return ['error' => $credentialValidation['error']];
        }

        return [
            'error' => null,
            'customer' => $customer,
            'type' => $type,
            'label' => $normalizedLabel,
            'config' => $config,
            'encrypted_credentials' => $credentialValidation['encrypted_credentials'],
        ];
    }

    private function normalizeConfig(BackupDestinationType $type, array $config): array
    {
        return match ($type) {
            BackupDestinationType::Local => $this->normalizeLocalConfig($config),
            BackupDestinationType::Nfs => $this->normalizeNfsConfig($config),
            BackupDestinationType::Smb => $this->normalizeSmbConfig($config),
            BackupDestinationType::Webdav, BackupDestinationType::Nextcloud => $this->normalizeWebdavConfig($config),
        };
    }

    private function normalizeLocalConfig(array $config): array
    {
        $path = trim((string) ($config['path'] ?? ''));
        if ($path === '') {
            return ['error' => new JsonResponse(['error' => 'Path is required for local targets.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        return [
            'error' => null,
            'config' => [
                'path' => $path,
            ],
        ];
    }

    private function normalizeNfsConfig(array $config): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        $exportPath = trim((string) ($config['export_path'] ?? ''));
        $mountPath = trim((string) ($config['mount_path'] ?? ''));
        $options = trim((string) ($config['options'] ?? ''));

        if ($host === '' || $exportPath === '' || $mountPath === '') {
            return ['error' => new JsonResponse(['error' => 'Host, export_path and mount_path are required for NFS targets.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        return [
            'error' => null,
            'config' => array_filter([
                'host' => $host,
                'export_path' => $exportPath,
                'mount_path' => $mountPath,
                'options' => $options !== '' ? $options : null,
            ], static fn ($value) => $value !== null),
        ];
    }

    private function normalizeSmbConfig(array $config): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        $share = trim((string) ($config['share'] ?? ''));
        $mountPath = trim((string) ($config['mount_path'] ?? ''));
        $domain = trim((string) ($config['domain'] ?? ''));
        $options = trim((string) ($config['options'] ?? ''));

        if ($host === '' || $share === '' || $mountPath === '') {
            return ['error' => new JsonResponse(['error' => 'Host, share and mount_path are required for SMB targets.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        return [
            'error' => null,
            'config' => array_filter([
                'host' => $host,
                'share' => $share,
                'mount_path' => $mountPath,
                'domain' => $domain !== '' ? $domain : null,
                'options' => $options !== '' ? $options : null,
            ], static fn ($value) => $value !== null),
        ];
    }

    private function normalizeWebdavConfig(array $config): array
    {
        $url = trim((string) ($config['url'] ?? ''));
        $rootPath = trim((string) ($config['root_path'] ?? ''));
        $verifyTlsValue = $config['verify_tls'] ?? true;

        if ($url === '') {
            return ['error' => new JsonResponse(['error' => 'URL is required for WebDAV targets.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $verifyTls = filter_var($verifyTlsValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verifyTls === null) {
            return ['error' => new JsonResponse(['error' => 'verify_tls must be a boolean.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        return [
            'error' => null,
            'config' => array_filter([
                'url' => $url,
                'root_path' => $rootPath !== '' ? $rootPath : null,
                'verify_tls' => $verifyTls,
            ], static fn ($value) => $value !== null),
        ];
    }

    private function normalizeCredentialsPayload(
        BackupDestinationType $type,
        array $payload,
        ?BackupTarget $target,
        bool $isCreate,
    ): array {
        $credentialsPayload = $payload['credentials'] ?? null;
        $clearKeys = $payload['clear_credentials'] ?? [];

        if ($credentialsPayload === null && $clearKeys === [] && !$isCreate) {
            return ['error' => null, 'encrypted_credentials' => null];
        }

        if ($credentialsPayload !== null && !is_array($credentialsPayload)) {
            return ['error' => new JsonResponse(['error' => 'Credentials must be an object.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        if ($clearKeys !== [] && !is_array($clearKeys)) {
            return ['error' => new JsonResponse(['error' => 'clear_credentials must be an array.'], JsonResponse::HTTP_BAD_REQUEST)];
        }

        $encryptedCredentials = $target?->getEncryptedCredentials() ?? [];

        if (is_array($credentialsPayload)) {
            foreach (self::CREDENTIAL_KEYS as $key) {
                $value = trim((string) ($credentialsPayload[$key] ?? ''));
                if ($value !== '') {
                    $encryptedCredentials[$key] = $this->encryptionService->encrypt($value);
                }
            }
        }

        foreach ($clearKeys as $key) {
            if (is_string($key)) {
                unset($encryptedCredentials[$key]);
            }
        }

        if ($isCreate && $credentialsPayload === null) {
            $encryptedCredentials = [];
        }

        $requiredValidation = $this->validateRequiredCredentials($type, $encryptedCredentials);
        if ($requiredValidation !== null) {
            return ['error' => $requiredValidation, 'encrypted_credentials' => null];
        }

        return [
            'error' => null,
            'encrypted_credentials' => $encryptedCredentials,
        ];
    }

    private function validateRequiredCredentials(BackupDestinationType $type, array $encryptedCredentials): ?JsonResponse
    {
        return match ($type) {
            BackupDestinationType::Smb => $this->requireCredentialPair($encryptedCredentials, 'username', 'password', 'SMB'),
            BackupDestinationType::Webdav, BackupDestinationType::Nextcloud => $this->requireWebdavCredentials($encryptedCredentials),
            default => null,
        };
    }

    private function requireCredentialPair(array $encryptedCredentials, string $first, string $second, string $label): ?JsonResponse
    {
        if (!array_key_exists($first, $encryptedCredentials) || !array_key_exists($second, $encryptedCredentials)) {
            return new JsonResponse(['error' => sprintf('%s credentials require %s and %s.', $label, $first, $second)], JsonResponse::HTTP_BAD_REQUEST);
        }

        return null;
    }

    private function requireWebdavCredentials(array $encryptedCredentials): ?JsonResponse
    {
        $hasToken = array_key_exists('token', $encryptedCredentials);
        $hasUserPass = array_key_exists('username', $encryptedCredentials) && array_key_exists('password', $encryptedCredentials);

        if (!$hasToken && !$hasUserPass) {
            return new JsonResponse(['error' => 'WebDAV credentials require token or username/password.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return null;
    }

    private function normalizeTarget(BackupTarget $target): array
    {
        return [
            'id' => $target->getId(),
            'customer_id' => $target->getCustomer()->getId(),
            'type' => $target->getType()->value,
            'label' => $target->getLabel(),
            'config' => $target->getConfig(),
            'credentials' => array_reduce(self::CREDENTIAL_KEYS, function (array $carry, string $key) use ($target): array {
                $carry[$key] = $target->hasEncryptedCredential($key);

                return $carry;
            }, []),
        ];
    }

    private function canAccessTarget(User $actor, BackupTarget $target): bool
    {
        return $actor->isAdmin() || $target->getCustomer()->getId() === $actor->getId();
    }
}
