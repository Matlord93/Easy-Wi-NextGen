<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Gameserver\Application\ConsoleCommandSettings;

/**
 * @psalm-import-type SettingValue from AppSettingsService
 * @psalm-import-type Settings from AppSettingsService
 */
interface AppSettingsServiceInterface extends ConsoleCommandSettings, \Symfony\Contracts\Service\ResetInterface
{
    /**
     * @return Settings
     */
    public function getSettings(): array;

    /**
     * @param array<string, mixed> $input
     *
     * @return Settings
     */
    public function updateSettings(array $input, bool $preserveSecrets = true): array;

    /**
     * Persistiert alle bekannten Default-Keys idempotent in die Datenbank (SoT).
     */
    public function ensureRuntimeDefaultsPersisted(): void;

    public function getSiteTitle(): string;

    public function getBrandingName(): string;

    public function getSupportEmail(): ?string;

    public function getMailFromName(): string;

    public function getMailFromAddress(): string;

    public function getMailReplyTo(): ?string;

    public function getMailDefaultLocale(): string;

    public function getAgentRegistrationToken(): string;

    public function hasAgentRegistrationTokenInDb(): bool;

    public function getAgentRegistrationTokenMasked(): string;

    public function rotateAgentRegistrationToken(): void;

    public function getGameserverDefaultSlots(): int;

    public function getGameserverMinSlots(): int;

    public function getGameserverMaxSlots(): int;

    public function isGameserverStartStopAllowed(): bool;

    public function isCustomerDataManagerEnabled(): bool;

    public function isCustomerFilePushEnabled(): bool;

    public function getCustomerConsoleLabel(): ?string;

    public function getInstanceBaseDir(): string;

    public function getSftpHost(): ?string;

    public function getSftpPort(): int;

    public function getSftpUsername(): ?string;

    public function getSftpPassword(): ?string;

    public function getSftpPrivateKey(): ?string;

    public function getSftpPrivateKeyPath(): ?string;

    public function getInvoiceLayout(): string;

    public function getSessionIdleTimeoutMinutes(): int;

    public function getSessionAbsoluteTimeoutMinutes(): int;

    public function getMaxConcurrentSessions(): int;

    public function isRegistrationEnabled(): bool;

    public function isAntiAbusePowEnabledForContact(): bool;

    public function isAntiAbusePowEnabledForRegistration(): bool;

    public function isAntiAbuseCaptchaEnabledForRegistration(): bool;

    public function isAntiAbuseCaptchaEnabledForContact(): bool;

    public function getAntiAbuseMinSubmitSeconds(): int;

    public function getAntiAbusePowDifficulty(): int;

    public function getAntiAbuseDailyIpLimit(): int;

    public function isPrivacyGdprJobsEnabled(): bool;

    public function getPrivacyGdprJobsInterval(): string;

    public function setPrivacyGdprJobsEnabled(bool $enabled): void;

    public function markPrivacyGdprJobsRun(string $status, ?string $error = null, ?\DateTimeImmutable $now = null): void;

    public function shouldStoreRoutineDatabaseLogs(): bool;

    public function getDatabaseLoggingRoutineRetentionDays(): int;

    public function markSchedulerHeartbeat(string $status, ?\DateTimeImmutable $now = null): void;
}
