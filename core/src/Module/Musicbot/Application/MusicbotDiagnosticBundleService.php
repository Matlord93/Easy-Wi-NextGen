<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Musicbot\Domain\Entity\MusicbotInstance;

/**
 * Builds an anonymised diagnostic bundle for a MusicbotInstance.
 *
 * Secrets and absolute file paths are masked before the bundle is returned.
 */
final class MusicbotDiagnosticBundleService
{
    private const SECRET_KEYS = [
        'password', 'token', 'secret', 'key', 'identity', 'credential', 'auth',
    ];

    /** @return array<string, mixed> */
    public function build(MusicbotInstance $instance, MusicbotHealthService $healthService): array
    {
        $runtime = $instance->getRuntimePayload() ?? [];
        $config = $instance->getInstanceConfig();
        $healthReport = $healthService->check($instance, adminView: true);

        return [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'instance' => [
                'id' => $instance->getId(),
                'name' => $instance->getName(),
                'status' => $instance->getStatus()->value,
                'service_name' => $instance->getServiceName(),
                'install_path' => $this->maskPath($instance->getInstallPath()),
                'node_id' => $instance->getNode()->getId(),
                'created_at' => $instance->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updated_at' => $instance->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'config' => $this->maskSecrets($config),
            'runtime_payload' => $this->maskSecrets($runtime),
            'health' => $healthReport,
            'last_error' => $instance->getLastError(),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function maskSecrets(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if ($this->isSecretKey((string) $key)) {
                $result[$key] = '***';
            } elseif (is_array($value)) {
                $result[$key] = $this->maskSecrets($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SECRET_KEYS as $secret) {
            if (str_contains($lower, $secret)) {
                return true;
            }
        }

        return false;
    }

    private function maskPath(string $path): string
    {
        return preg_replace('#/opt/[^/]+/#', '/opt/***/', $path) ?? $path;
    }
}
