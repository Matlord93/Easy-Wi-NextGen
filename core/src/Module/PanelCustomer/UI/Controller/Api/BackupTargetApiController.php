<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Api;

use App\Infrastructure\Config\JsonArray;
use App\Infrastructure\Config\ScalarValue;
use App\Infrastructure\Http\RequestActor;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @psalm-type ValidTarget = array{error: null, customer: User, type: ?BackupDestinationType,
 * label: ?string, config: ?array<array-key, mixed>, encrypted_credentials: ?array<array-key, mixed>}
 * @psalm-type ValidConfig = array{error: null, config: array<array-key, mixed>}
 * @psalm-type ValidCredentials = array{error: null, encrypted_credentials: ?array<array-key, mixed>}
 */
final class BackupTargetApiController
{
    private const CREDENTIAL_KEYS = ['username', 'password', 'token', 'access_key', 'secret_key'];

    public function __construct(
        private readonly BackupTargetRepository $backupTargetRepository,
        private readonly UserRepository $userRepository,
        private readonly EncryptionService $encryptionService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly TranslatorInterface $translator,
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
        if ($validation instanceof JsonResponse) {
            return $validation;
        }

        if ($validation['type'] === null || $validation['label'] === null || $validation['config'] === null) {
            throw new \LogicException('Create validation did not supply the required backup target fields.');
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
            return new JsonResponse(['error' => $this->translator->trans('backup_target_not_found')], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessTarget($actor, $target)) {
            return new JsonResponse(['error' => $this->translator->trans('error_forbidden')], JsonResponse::HTTP_FORBIDDEN);
        }

        $payload = $this->parseJsonPayload($request);
        $validation = $this->validateTargetPayload($actor, $payload, false, $target);
        if ($validation instanceof JsonResponse) {
            return $validation;
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
            return new JsonResponse(['error' => $this->translator->trans('backup_target_not_found')], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessTarget($actor, $target)) {
            return new JsonResponse(['error' => $this->translator->trans('error_forbidden')], JsonResponse::HTTP_FORBIDDEN);
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
        $actor = RequestActor::user($request);
        if (!$actor instanceof User) {
            throw new \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('session', $this->translator->trans('error_unauthorized'));
        }

        return $actor;
    }

    /** @return array<array-key, mixed> */
    private function parseJsonPayload(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\JsonException $exception) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid JSON payload.', $exception);
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return ValidTarget|JsonResponse
     */
    private function validateTargetPayload(User $actor, array $payload, bool $isCreate, ?BackupTarget $target = null): array|JsonResponse
    {
        $customerId = $this->numericId($payload['customer_id'] ?? null);

        $customer = $actor;
        if ($actor->isAdmin()) {
            if ($isCreate && $customerId === null) {
                return new JsonResponse(['error' => 'Customer is required.'], JsonResponse::HTTP_BAD_REQUEST);
            }

            if ($customerId !== null) {
                $customer = $this->userRepository->find($customerId);
                if ($customer === null || $customer->getType() !== UserType::Customer) {
                    return new JsonResponse(['error' => $this->translator->trans('error_customer_not_found')], JsonResponse::HTTP_NOT_FOUND);
                }
            } elseif ($target !== null) {
                $customer = $target->getCustomer();
            }
        } elseif ($target !== null) {
            $customer = $target->getCustomer();
        }

        $type = null;
        if (($payload['type'] ?? null) !== null) {
            $typeValue = strtolower(trim(ScalarValue::text($payload['type'])));
            $type = BackupDestinationType::tryFrom($typeValue);
            if ($type === null) {
                return new JsonResponse(['error' => $this->translator->trans('backup_target_type_invalid')], JsonResponse::HTTP_BAD_REQUEST);
            }
        } elseif ($isCreate) {
            return new JsonResponse(['error' => $this->translator->trans('backup_target_type_required')], JsonResponse::HTTP_BAD_REQUEST);
        }

        $normalizedLabel = null;
        if (($payload['label'] ?? null) !== null) {
            $normalizedLabel = trim(ScalarValue::text($payload['label']));
            if ($normalizedLabel === '') {
                return new JsonResponse(['error' => $this->translator->trans('backup_label_required')], JsonResponse::HTTP_BAD_REQUEST);
            }
        } elseif ($isCreate) {
            return new JsonResponse(['error' => $this->translator->trans('backup_label_required')], JsonResponse::HTTP_BAD_REQUEST);
        }

        $effectiveType = $type ?? $target?->getType();
        if ($effectiveType === null) {
            return new JsonResponse(['error' => $this->translator->trans('backup_target_type_required')], JsonResponse::HTTP_BAD_REQUEST);
        }

        $config = null;
        $typeChanged = $type !== null && $target !== null && $target->getType() !== $type;
        if ($typeChanged && !array_key_exists('config', $payload)) {
            return new JsonResponse(['error' => $this->translator->trans('backup_config_required')], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('config', $payload) || $isCreate) {
            $configPayload = JsonArray::fromValue($payload['config'] ?? null);
            if (!is_array($configPayload)) {
                return new JsonResponse(['error' => $this->translator->trans('backup_config_must_be_object')], JsonResponse::HTTP_BAD_REQUEST);
            }
            $configValidation = $this->normalizeConfig($effectiveType, $configPayload);
            if ($configValidation instanceof JsonResponse) {
                return $configValidation;
            }
            $config = $configValidation['config'];
        }

        $credentialValidation = $this->normalizeCredentialsPayload($effectiveType, $payload, $target, $isCreate);
        if ($credentialValidation instanceof JsonResponse) {
            return $credentialValidation;
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

    /**
     * @param array<array-key, mixed> $config
     * @return ValidConfig|JsonResponse
     */
    private function normalizeConfig(BackupDestinationType $type, array $config): array|JsonResponse
    {
        return match ($type) {
            BackupDestinationType::Local => $this->normalizeLocalConfig($config),
            BackupDestinationType::S3 => $this->normalizeS3Config($config),
            BackupDestinationType::Nfs => $this->normalizeNfsConfig($config),
            BackupDestinationType::Smb => $this->normalizeSmbConfig($config),
            BackupDestinationType::Webdav, BackupDestinationType::Nextcloud => $this->normalizeWebdavConfig($config),
        };
    }

    /**
     * @param array<array-key, mixed> $config
     * @return ValidConfig|JsonResponse
     */
    private function normalizeS3Config(array $config): array|JsonResponse
    {
        foreach (['bucket', 'region', 'endpoint', 'prefix'] as $key) {
            if (isset($config[$key]) && !is_string($config[$key])) {
                return new JsonResponse(['error' => sprintf('S3 %s must be a string.', $key)], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        $bucket = trim(ScalarValue::text($config['bucket'] ?? ''));
        $region = trim(ScalarValue::text($config['region'] ?? 'us-east-1'));
        $endpoint = rtrim(trim(ScalarValue::text($config['endpoint'] ?? '')), '/');
        $prefix = trim(ScalarValue::text($config['prefix'] ?? ''), '/');
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) !== 1 || str_contains($bucket, '..')) {
            return new JsonResponse(['error' => 'A valid S3 bucket name is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if (preg_match('/^[a-z0-9-]+$/D', $region) !== 1) {
            return new JsonResponse(['error' => 'A valid S3 region is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        if ($endpoint !== '') {
            $parts = parse_url($endpoint);
            if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                return new JsonResponse(['error' => 'S3 endpoint must be an HTTP(S) URL without credentials, query or fragment.'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }

        return [
            'error' => null,
            'config' => [
                'bucket' => $bucket,
                'region' => $region,
                'endpoint' => $endpoint,
                'prefix' => $prefix,
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $config
     * @return ValidConfig|JsonResponse
     */
    private function normalizeLocalConfig(array $config): array|JsonResponse
    {
        $path = trim(ScalarValue::text($config['path'] ?? ''));
        if ($path === '') {
            return new JsonResponse(['error' => $this->translator->trans('backup_path_required')], JsonResponse::HTTP_BAD_REQUEST);
        }

        return [
            'error' => null,
            'config' => [
                'path' => $path,
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $config
     * @return ValidConfig|JsonResponse
     */
    private function normalizeNfsConfig(array $config): array|JsonResponse
    {
        $host = trim(ScalarValue::text($config['host'] ?? ''));
        $exportPath = trim(ScalarValue::text($config['export_path'] ?? ''));
        $mountPath = trim(ScalarValue::text($config['mount_path'] ?? ''));
        $options = trim(ScalarValue::text($config['options'] ?? ''));

        if ($host === '' || $exportPath === '' || $mountPath === '') {
            return new JsonResponse(['error' => $this->translator->trans('backup_nfs_required_fields')], JsonResponse::HTTP_BAD_REQUEST);
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

    /**
     * @param array<array-key, mixed> $config
     * @return ValidConfig|JsonResponse
     */
    private function normalizeSmbConfig(array $config): array|JsonResponse
    {
        $host = trim(ScalarValue::text($config['host'] ?? ''));
        $share = trim(ScalarValue::text($config['share'] ?? ''));
        $mountPath = trim(ScalarValue::text($config['mount_path'] ?? ''));
        $domain = trim(ScalarValue::text($config['domain'] ?? ''));
        $options = trim(ScalarValue::text($config['options'] ?? ''));

        if ($host === '' || $share === '' || $mountPath === '') {
            return new JsonResponse(['error' => $this->translator->trans('backup_smb_required_fields')], JsonResponse::HTTP_BAD_REQUEST);
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

    /**
     * @param array<array-key, mixed> $config
     * @return ValidConfig|JsonResponse
     */
    private function normalizeWebdavConfig(array $config): array|JsonResponse
    {
        $url = trim(ScalarValue::text($config['url'] ?? ''));
        $rootPath = trim(ScalarValue::text($config['root_path'] ?? ''));

        if ($url === '') {
            return new JsonResponse(['error' => $this->translator->trans('backup_webdav_url_required')], JsonResponse::HTTP_BAD_REQUEST);
        }

        $verifyTls = filter_var($config['verify_tls'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verifyTls === null) {
            return new JsonResponse(['error' => $this->translator->trans('backup_verify_tls_must_be_bool')], JsonResponse::HTTP_BAD_REQUEST);
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

    /**
     * @param array<array-key, mixed> $payload
     * @return ValidCredentials|JsonResponse
     */
    private function normalizeCredentialsPayload(
        BackupDestinationType $type,
        array $payload,
        ?BackupTarget $target,
        bool $isCreate,
    ): array|JsonResponse {
        $credentialsPayload = JsonArray::fromValue($payload['credentials'] ?? null);
        $clearKeys = JsonArray::fromValue($payload['clear_credentials'] ?? []);

        if (($payload['credentials'] ?? null) === null && $clearKeys === [] && !$isCreate) {
            return ['error' => null, 'encrypted_credentials' => null];
        }

        if (($payload['credentials'] ?? null) !== null && $credentialsPayload === null) {
            return new JsonResponse(['error' => $this->translator->trans('backup_credentials_must_be_object')], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($clearKeys === null) {
            return new JsonResponse(['error' => $this->translator->trans('backup_clear_credentials_must_be_array')], JsonResponse::HTTP_BAD_REQUEST);
        }

        $encryptedCredentials = $target?->getEncryptedCredentials() ?? [];

        if (is_array($credentialsPayload)) {
            foreach (self::CREDENTIAL_KEYS as $key) {
                $value = trim(ScalarValue::text($credentialsPayload[$key] ?? ''));
                if ($value !== '') {
                    $encryptedCredentials[$key] = $this->encryptionService->encrypt($value);
                }
            }
        }

        foreach (array_keys($clearKeys) as $index) {
            if (is_string($clearKeys[$index])) {
                unset($encryptedCredentials[$clearKeys[$index]]);
            }
        }

        if ($isCreate && $credentialsPayload === null) {
            $encryptedCredentials = [];
        }

        $requiredValidation = $this->validateRequiredCredentials($type, $encryptedCredentials);
        if ($requiredValidation !== null) {
            return $requiredValidation;
        }

        return [
            'error' => null,
            'encrypted_credentials' => $encryptedCredentials,
        ];
    }

    private function validateRequiredCredentials(BackupDestinationType $type, array $encryptedCredentials): ?JsonResponse
    {
        return match ($type) {
            BackupDestinationType::S3 => $this->requireCredentialPair($encryptedCredentials, 'access_key', 'secret_key', 'S3'),
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
            return new JsonResponse(['error' => $this->translator->trans('backup_webdav_credentials_required')], JsonResponse::HTTP_BAD_REQUEST);
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

    private function numericId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function canAccessTarget(User $actor, BackupTarget $target): bool
    {
        return $actor->isAdmin() || $target->getCustomer()->getId() === $actor->getId();
    }
}
