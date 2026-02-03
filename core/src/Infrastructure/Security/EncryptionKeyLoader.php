<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final class EncryptionKeyLoader
{
    private const DEFAULT_KEY_PATH = '/etc/easywi/secret.key';

    public function __construct(
        private readonly string $keyPath = self::DEFAULT_KEY_PATH,
    ) {
    }

    public function getKeyPath(): string
    {
        return $this->keyPath;
    }

    public function isReadable(): bool
    {
        return is_readable($this->keyPath);
    }

    /**
     * @return array{active_key_id: string, keyring: string}
     */
    public function loadKeyring(): array
    {
        if (!$this->isReadable()) {
            return ['active_key_id' => '', 'keyring' => ''];
        }

        $contents = file_get_contents($this->keyPath);
        if ($contents === false) {
            return ['active_key_id' => '', 'keyring' => ''];
        }

        $contents = trim($contents);
        if ($contents === '') {
            return ['active_key_id' => '', 'keyring' => ''];
        }

        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            $keyring = '';
            if (isset($decoded['keyring']) && is_string($decoded['keyring'])) {
                $keyring = trim($decoded['keyring']);
            } elseif (isset($decoded['keys']) && is_array($decoded['keys'])) {
                $entries = [];
                foreach ($decoded['keys'] as $keyId => $value) {
                    if (!is_string($keyId) || !is_string($value)) {
                        continue;
                    }
                    $keyId = trim($keyId);
                    $value = trim($value);
                    if ($keyId !== '' && $value !== '') {
                        $entries[] = sprintf('%s:%s', $keyId, $value);
                    }
                }
                $keyring = implode(',', $entries);
            }

            $activeKeyId = '';
            if (isset($decoded['active_key_id']) && is_string($decoded['active_key_id'])) {
                $activeKeyId = trim($decoded['active_key_id']);
            }
            if ($activeKeyId === '' && $keyring !== '') {
                $activeKeyId = trim((string) strtok($keyring, ':'));
            }

            return ['active_key_id' => $activeKeyId, 'keyring' => $keyring];
        }

        if (str_contains($contents, ':')) {
            $activeKeyId = trim((string) strtok($contents, ':'));

            return ['active_key_id' => $activeKeyId, 'keyring' => $contents];
        }

        return ['active_key_id' => 'v1', 'keyring' => 'v1:' . $contents];
    }
}
