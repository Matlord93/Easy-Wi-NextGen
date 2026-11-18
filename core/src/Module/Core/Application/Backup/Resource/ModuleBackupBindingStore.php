<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Backup\Resource;

use App\Module\Core\Application\SecretsCryptoInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Only the administrator CLI writes bindings; credentials remain encrypted at rest. */
final class ModuleBackupBindingStore
{
    public function __construct(private readonly Connection $connection, private readonly SecretsCryptoInterface $crypto)
    {
    }

    /** @param array<array-key, mixed> $binding */
    public function save(string $module, string $resourceId, array $binding): void
    {
        self::validate($binding);
        $key = self::key($module, $resourceId);
        $payload = $this->crypto->encrypt(json_encode(['module' => $module, 'resource_id' => $resourceId, 'binding' => $binding], JSON_THROW_ON_ERROR));
        if ($this->connection->update('backup_module_bindings', ['payload' => $payload], ['record_key' => $key]) > 0) {
            return;
        }
        try {
            $this->connection->insert('backup_module_bindings', ['record_key' => $key, 'payload' => $payload]);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('backup_module_bindings', ['payload' => $payload], ['record_key' => $key]);
        }
    }

    /** @return array{agent_id: string, path?: string, service?: string, services?: non-empty-list<string>, additional_paths?: array<string, string>}|null */
    public function find(string $module, string $resourceId): ?array
    {
        $payload = $this->connection->fetchOne('SELECT payload FROM backup_module_bindings WHERE record_key = ?', [self::key($module, $resourceId)]);
        if ($payload === false) {
            return null;
        }
        if (!is_string($payload)) {
            throw new \RuntimeException('Invalid backup resource binding.');
        }
        $record = json_decode($this->crypto->decrypt($payload), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['module'] ?? null) !== $module || ($record['resource_id'] ?? null) !== $resourceId) {
            throw new \RuntimeException('Backup binding identity does not match its key.');
        }
        $binding = $record['binding'] ?? null;
        if (!is_array($binding)) {
            throw new \RuntimeException('Invalid backup binding configuration.');
        }
        self::validate($binding);
        return $binding;
    }

    private static function key(string $module, string $resourceId): string
    {
        if (!in_array($module, ['mail', 'voice', 'game', 'db'], true) || $resourceId === '' || strlen($resourceId) > 190) {
            throw new \InvalidArgumentException('Invalid managed backup module or resource ID.');
        }
        return hash('sha256', $module."\0".$resourceId);
    }

    /**
     * @param array<array-key, mixed> $binding
     * @psalm-assert array{agent_id: string, path?: string, service?: string, services?: non-empty-list<string>, additional_paths?: array<string, string>} $binding
     */
    public static function validate(array $binding): void
    {
        if (array_diff(array_keys($binding), ['agent_id', 'path', 'service', 'services', 'additional_paths']) !== [] || !is_string($binding['agent_id'] ?? null) || $binding['agent_id'] === '') {
            throw new \InvalidArgumentException('Binding requires agent_id and only path, service, services, additional_paths are supported.');
        }
        if (array_key_exists('path', $binding)) {
            self::assertPath($binding['path']);
        }
        if (array_key_exists('service', $binding) && (!is_string($binding['service']) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.@-]{0,190}$/D', $binding['service']))) {
            throw new \InvalidArgumentException('Invalid backup service unit.');
        }
        if (array_key_exists('services', $binding)) {
            if (!is_array($binding['services']) || !array_is_list($binding['services']) || $binding['services'] === [] || count($binding['services']) > 16) {
                throw new \InvalidArgumentException('Services must be a nonempty list of at most 16 units.');
            }
            /** @var mixed $service */
            foreach ($binding['services'] as $service) {
                if (!is_string($service) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.@-]{0,190}$/D', $service)) {
                    throw new \InvalidArgumentException('Invalid backup service unit.');
                }
            }
        }
        if (array_key_exists('additional_paths', $binding)) {
            if (!is_array($binding['additional_paths']) || count($binding['additional_paths']) > 16) {
                throw new \InvalidArgumentException('Invalid additional backup paths.');
            }
            /** @var mixed $path */
            foreach ($binding['additional_paths'] as $name => $path) {
                if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $name)) {
                    throw new \InvalidArgumentException('Invalid additional path name.');
                }
                self::assertPath($path);
            }
        }
    }

    private static function assertPath(mixed $path): void
    {
        if (!is_string($path) || !str_starts_with($path, '/') || $path === '/' || str_contains($path, "\0") || str_contains($path, '\\') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $path)) {
            throw new \InvalidArgumentException('Backup paths must be absolute non-root paths without dot components.');
        }
    }
}
