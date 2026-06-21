<?php

declare(strict_types=1);

namespace App\Module\Musicbot\Application;

use App\Module\Core\Application\AuditLogger;
use App\Module\Core\Domain\Entity\User;
use App\Module\Musicbot\Domain\Entity\MusicbotInstance;
use App\Module\Musicbot\Domain\Entity\MusicbotStreamSettings;
use App\Module\Musicbot\Domain\Enum\MusicbotStreamAccessMode;
use App\Module\Musicbot\Infrastructure\Stream\StreamOutputInterface;
use App\Repository\MusicbotStreamSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MusicbotStreamService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MusicbotStreamSettingsRepository $settingsRepository,
        private readonly MusicbotQuotaService $quotaService,
        private readonly MusicbotRuntimeEventService $runtimeEventService,
        private readonly StreamOutputInterface $streamOutput,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function getOrCreateSettings(MusicbotInstance $instance): MusicbotStreamSettings
    {
        return $this->settingsRepository->findByInstance($instance)
            ?? new MusicbotStreamSettings(
                $instance->getCustomer(),
                $instance,
                $this->generateSlug($instance),
            );
    }

    /**
     * Persists or updates stream settings from user-supplied data.
     *
     * @param array<string, mixed> $data
     */
    public function saveSettings(User $customer, MusicbotInstance $instance, array $data): MusicbotStreamSettings
    {
        $this->assertOwnership($customer, $instance);
        $this->quotaService->assertWebradioAllowed($customer);

        $settings = $this->settingsRepository->findByInstance($instance);
        if ($settings === null) {
            $settings = new MusicbotStreamSettings($customer, $instance, $this->generateSlug($instance));
            $this->entityManager->persist($settings);
        }

        if (array_key_exists('access_mode', $data)) {
            $mode = MusicbotStreamAccessMode::tryFrom((string) $data['access_mode']);
            if ($mode === null) {
                throw new \InvalidArgumentException(sprintf('Invalid access mode: "%s".', $data['access_mode']));
            }
            $settings->setAccessMode($mode);
        }

        if (array_key_exists('stream_title', $data)) {
            $settings->setStreamTitle($data['stream_title'] !== '' ? (string) $data['stream_title'] : null);
        }

        if (array_key_exists('bitrate', $data)) {
            $settings->setBitrate((int) $data['bitrate']);
        }

        if (array_key_exists('format', $data)) {
            $settings->setFormat((string) $data['format']);
        }

        $this->entityManager->flush();
        $this->auditLogger->log($customer, 'musicbot.stream_settings_updated', ['instance_id' => $instance->getId()]);

        return $settings;
    }

    public function enable(User $customer, MusicbotInstance $instance): MusicbotStreamSettings
    {
        $this->assertOwnership($customer, $instance);
        $this->quotaService->assertWebradioAllowed($customer);

        $settings = $this->settingsRepository->findByInstance($instance);
        if ($settings === null) {
            $settings = new MusicbotStreamSettings($customer, $instance, $this->generateSlug($instance));
            $this->entityManager->persist($settings);
        }

        $settings->setEnabled(true);
        if ($this->streamOutput->isAvailable()) {
            $startStatus = $this->streamOutput->start($settings);
            if (($startStatus['started'] ?? false) === true) {
                $settings->setCurrentMountPath(isset($startStatus['mount_path']) ? (string) $startStatus['mount_path'] : null);
            } else {
                $this->runtimeEventService->record($instance, 'stream.error', 'error', (string) ($startStatus['message'] ?? 'Stream backend did not start.'), []);
            }
        }
        $this->entityManager->flush();

        $this->runtimeEventService->record($instance, 'stream.enabled', 'info', 'Webradio stream mode enabled. Placeholder mode does not broadcast audio.', []);
        $this->auditLogger->log($customer, 'musicbot.stream_enabled', ['instance_id' => $instance->getId()]);

        return $settings;
    }

    public function disable(User $customer, MusicbotInstance $instance): MusicbotStreamSettings
    {
        $this->assertOwnership($customer, $instance);

        $settings = $this->settingsRepository->findByInstance($instance);
        if ($settings === null) {
            $settings = new MusicbotStreamSettings($customer, $instance, $this->generateSlug($instance));
            $this->entityManager->persist($settings);
        }

        $settings->setEnabled(false);
        if ($this->streamOutput->isAvailable()) {
            $stopStatus = $this->streamOutput->stop($settings);
            if (($stopStatus['stopped'] ?? false) !== true) {
                $this->runtimeEventService->record($instance, 'stream.error', 'error', (string) ($stopStatus['message'] ?? 'Stream backend did not stop cleanly.'), []);
            }
        }
        $settings->setCurrentMountPath(null);
        $this->entityManager->flush();

        $this->runtimeEventService->record($instance, 'stream.disabled', 'info', 'Webradio stream mode disabled.', []);
        $this->auditLogger->log($customer, 'musicbot.stream_disabled', ['instance_id' => $instance->getId()]);

        return $settings;
    }

    /**
     * Generates a new random stream token, stores its hash, and returns the plaintext token.
     * The plaintext is shown once — only the hash is persisted.
     */
    public function rotateToken(User $customer, MusicbotInstance $instance): array
    {
        $this->assertOwnership($customer, $instance);
        $this->quotaService->assertWebradioAllowed($customer);

        $settings = $this->settingsRepository->findByInstance($instance);
        if ($settings === null) {
            $settings = new MusicbotStreamSettings($customer, $instance, $this->generateSlug($instance));
            $this->entityManager->persist($settings);
        }

        $token = bin2hex(random_bytes(32));
        $settings->setStreamTokenHash(password_hash($token, PASSWORD_ARGON2ID));
        $this->entityManager->flush();

        $this->runtimeEventService->record($instance, 'stream.token_rotated', 'info', 'Stream access token rotated.', []);
        $this->auditLogger->log($customer, 'musicbot.stream_token_rotated', ['instance_id' => $instance->getId()]);

        return ['token' => $token, 'settings' => $settings];
    }

    /**
     * Returns the stream status for the instance.
     *
     * @return array<string, mixed>
     */
    public function getStatus(MusicbotInstance $instance): array
    {
        $settings = $this->settingsRepository->findByInstance($instance);
        $backendStatus = $settings !== null
            ? $this->streamOutput->getStatus($settings)
            : ['stream_ready' => false, 'backend' => 'placeholder', 'message' => 'No stream settings configured.'];

        return array_merge($backendStatus, [
            'stream_enabled' => $settings?->isEnabled() ?? false,
            'stream_url_placeholder' => $settings instanceof MusicbotStreamSettings ? sprintf('/stream/%s', $settings->getPublicSlug()) : null,
            'backend_available' => $this->streamOutput->isAvailable(),
        ]);
    }

    /** @return array<string, mixed> */
    public function normalize(MusicbotStreamSettings $settings): array
    {
        return [
            'id' => $settings->getId(),
            'instance_id' => $settings->getInstance()->getId(),
            'enabled' => $settings->isEnabled(),
            'public_slug' => $settings->getPublicSlug(),
            'access_mode' => $settings->getAccessMode()->value,
            'stream_title' => $settings->getStreamTitle(),
            'bitrate' => $settings->getBitrate(),
            'format' => $settings->getFormat(),
            'current_mount_path' => $settings->getCurrentMountPath(),
            'has_token' => $settings->getStreamTokenHash() !== null,
            'stream_ready' => false,
            'stream_url_placeholder' => sprintf('/stream/%s', $settings->getPublicSlug()),
            'placeholder_notice' => 'Streaming support is prepared, but no real stream backend is active yet.',
            'backend' => 'placeholder',
            'backend_available' => $this->streamOutput->isAvailable(),
            'created_at' => $settings->getCreatedAt()->format(DATE_ATOM),
            'updated_at' => $settings->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function assertOwnership(User $customer, MusicbotInstance $instance): void
    {
        if ($instance->getCustomer()->getId() !== $customer->getId()) {
            throw new \App\Module\Musicbot\Domain\Exception\MusicbotPermissionDeniedException('Access denied.');
        }
    }

    private function generateSlug(MusicbotInstance $instance): string
    {
        return sprintf('mb-%d-%s', $instance->getId() ?? 0, substr(bin2hex(random_bytes(8)), 0, 12));
    }
}
