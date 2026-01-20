<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

use App\Module\Core\Domain\Entity\AppSetting;
use App\Repository\AppSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AppSettingsService
{
    public const KEY_SITE_TITLE = 'site_title';
    public const KEY_BRANDING_NAME = 'branding_name';
    public const KEY_BRANDING_LOGO_URL = 'branding_logo_url';
    public const KEY_SUPPORT_EMAIL = 'support_email';
    public const KEY_MAIL_FROM_NAME = 'mail_from_name';
    public const KEY_MAIL_FROM_ADDRESS = 'mail_from_address';
    public const KEY_MAIL_REPLY_TO = 'mail_reply_to';
    public const KEY_MAIL_DEFAULT_LOCALE = 'mail_default_locale';
    public const KEY_GAMESERVER_DEFAULT_SLOTS = 'gameserver_default_slots';
    public const KEY_GAMESERVER_MIN_SLOTS = 'gameserver_min_slots';
    public const KEY_GAMESERVER_MAX_SLOTS = 'gameserver_max_slots';
    public const KEY_GAMESERVER_SHOW_PORT_RANGE = 'gameserver_show_port_range';
    public const KEY_GAMESERVER_ALLOW_START_STOP = 'gameserver_allow_start_stop';
    public const KEY_CUSTOMER_DATA_MANAGER_ENABLED = 'customer_data_manager_enabled';
    public const KEY_CUSTOMER_FILE_PUSH_ENABLED = 'customer_file_push_enabled';
    public const KEY_CUSTOMER_CONSOLE_LABEL = 'customer_console_label';
    public const KEY_CUSTOMER_LOGS_LABEL = 'customer_logs_label';
    public const KEY_INSTANCE_BASE_DIR = 'instance_base_dir';
    public const KEY_SFTP_HOST = 'sftp_host';
    public const KEY_SFTP_PORT = 'sftp_port';
    public const KEY_SFTP_USERNAME = 'sftp_username';
    public const KEY_SFTP_PASSWORD = 'sftp_password';
    public const KEY_SFTP_PRIVATE_KEY = 'sftp_private_key';
    public const KEY_SFTP_PRIVATE_KEY_PATH = 'sftp_private_key_path';
    public const KEY_SFTP_PRIVATE_KEY_PASSPHRASE = 'sftp_private_key_passphrase';
    public const KEY_INVOICE_LAYOUT = 'invoice_layout_html';

    private const DEFAULT_INVOICE_LAYOUT = <<<'TWIG'
<div style="font-family: Arial, sans-serif; max-width: 720px; margin: 0 auto;">
  <div style="display: flex; justify-content: space-between; gap: 24px;">
    <div>
      <h1 style="margin: 0 0 8px;">{{ company.name }}</h1>
      <p style="margin: 0; font-size: 12px; color: #555;">{{ company.address }}</p>
      <p style="margin: 0; font-size: 12px; color: #555;">{{ company.email }}</p>
    </div>
    <div style="text-align: right;">
      <h2 style="margin: 0 0 8px;">Invoice {{ invoice.number }}</h2>
      <p style="margin: 0; font-size: 12px; color: #555;">Date {{ invoice.created_at|date('Y-m-d') }}</p>
      <p style="margin: 0; font-size: 12px; color: #555;">Due {{ invoice.due_date|date('Y-m-d') }}</p>
    </div>
  </div>

  <hr style="margin: 20px 0; border: 0; border-top: 1px solid #e5e7eb;">

  <h3 style="margin: 0 0 8px;">Bill to</h3>
  <p style="margin: 0;">{{ customer.name }}</p>
  <p style="margin: 0; font-size: 12px; color: #555;">{{ customer.email }}</p>

  <table style="width: 100%; margin-top: 20px; border-collapse: collapse;">
    <thead>
      <tr>
        <th style="text-align: left; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">Description</th>
        <th style="text-align: right; padding: 8px 0; border-bottom: 1px solid #e5e7eb;">Amount</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td style="padding: 12px 0;">Invoice {{ invoice.number }}</td>
        <td style="padding: 12px 0; text-align: right;">{{ invoice.amount_total / 100 }} {{ invoice.currency }}</td>
      </tr>
    </tbody>
  </table>

  <div style="margin-top: 16px; text-align: right;">
    <p style="margin: 0; font-weight: bold;">Total: {{ invoice.amount_total / 100 }} {{ invoice.currency }}</p>
    <p style="margin: 4px 0 0; font-size: 12px; color: #555;">Amount due: {{ invoice.amount_due / 100 }} {{ invoice.currency }}</p>
  </div>

  <p style="margin-top: 24px; font-size: 12px; color: #555;">{{ company.footer }}</p>
</div>
TWIG;

    private const DEFAULTS = [
        self::KEY_SITE_TITLE => 'Easy-Wi NextGen',
        self::KEY_BRANDING_NAME => 'Easy-Wi NextGen',
        self::KEY_BRANDING_LOGO_URL => null,
        self::KEY_SUPPORT_EMAIL => null,
        self::KEY_MAIL_FROM_NAME => null,
        self::KEY_MAIL_FROM_ADDRESS => null,
        self::KEY_MAIL_REPLY_TO => null,
        self::KEY_MAIL_DEFAULT_LOCALE => 'en',
        self::KEY_GAMESERVER_DEFAULT_SLOTS => 16,
        self::KEY_GAMESERVER_MIN_SLOTS => 1,
        self::KEY_GAMESERVER_MAX_SLOTS => 128,
        self::KEY_GAMESERVER_SHOW_PORT_RANGE => true,
        self::KEY_GAMESERVER_ALLOW_START_STOP => true,
        self::KEY_CUSTOMER_DATA_MANAGER_ENABLED => true,
        self::KEY_CUSTOMER_FILE_PUSH_ENABLED => true,
        self::KEY_CUSTOMER_CONSOLE_LABEL => null,
        self::KEY_CUSTOMER_LOGS_LABEL => null,
        self::KEY_INSTANCE_BASE_DIR => '/home',
        self::KEY_SFTP_HOST => null,
        self::KEY_SFTP_PORT => 22,
        self::KEY_SFTP_USERNAME => null,
        self::KEY_SFTP_PASSWORD => null,
        self::KEY_SFTP_PRIVATE_KEY => null,
        self::KEY_SFTP_PRIVATE_KEY_PATH => null,
        self::KEY_SFTP_PRIVATE_KEY_PASSPHRASE => null,
        self::KEY_INVOICE_LAYOUT => self::DEFAULT_INVOICE_LAYOUT,
    ];

    private const SECRET_KEYS = [
        self::KEY_SFTP_PASSWORD,
        self::KEY_SFTP_PRIVATE_KEY,
        self::KEY_SFTP_PRIVATE_KEY_PASSPHRASE,
    ];

    /** @var array<string, mixed>|null */
    private ?array $cachedSettings = null;

    public function __construct(
        private readonly AppSettingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        if ($this->cachedSettings !== null) {
            return $this->cachedSettings;
        }

        $settings = self::DEFAULTS;

        try {
            foreach ($this->repository->findAll() as $setting) {
                $settings[$setting->getSettingKey()] = $setting->getValue();
            }
        } catch (\Throwable) {
            $this->cachedSettings = $this->normalizeSettings($settings);
            return $this->cachedSettings;
        }

        $this->cachedSettings = $this->normalizeSettings($settings);

        return $this->cachedSettings;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function updateSettings(array $input, bool $preserveSecrets = true): array
    {
        $current = $this->getSettings();
        $merged = $current;

        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if ($preserveSecrets && in_array($key, self::SECRET_KEYS, true)) {
                if (is_string($value) && trim($value) === '') {
                    continue;
                }
            }

            $merged[$key] = $value;
        }

        $normalized = $this->normalizeSettings($merged);
        $this->persistSettings($normalized);
        $this->cachedSettings = $normalized;

        return $normalized;
    }

    public function getSiteTitle(): string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_SITE_TITLE] ?? null;

        return is_string($value) && $value !== '' ? $value : self::DEFAULTS[self::KEY_SITE_TITLE];
    }

    public function getBrandingName(): string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_BRANDING_NAME] ?? null;

        return is_string($value) && $value !== '' ? $value : $this->getSiteTitle();
    }

    public function getBrandingLogoUrl(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_BRANDING_LOGO_URL] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getSupportEmail(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_SUPPORT_EMAIL] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fallback = $this->getMailFromAddress();
        return $fallback !== '' ? $fallback : null;
    }

    public function getMailFromName(): string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_MAIL_FROM_NAME] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['MAIL_FROM_NAME'] ?? $_SERVER['MAIL_FROM_NAME'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return self::DEFAULTS[self::KEY_SITE_TITLE];
    }

    public function getMailFromAddress(): string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_MAIL_FROM_ADDRESS] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['MAIL_FROM_ADDRESS'] ?? $_SERVER['MAIL_FROM_ADDRESS'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return 'no-reply@example.com';
    }

    public function getMailReplyTo(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_MAIL_REPLY_TO] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['MAIL_REPLY_TO'] ?? $_SERVER['MAIL_REPLY_TO'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }

    public function getMailDefaultLocale(): string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_MAIL_DEFAULT_LOCALE] ?? null;

        if (is_string($value) && in_array($value, ['de', 'en'], true)) {
            return $value;
        }

        return self::DEFAULTS[self::KEY_MAIL_DEFAULT_LOCALE];
    }

    public function getGameserverDefaultSlots(): int
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_GAMESERVER_DEFAULT_SLOTS] ?? self::DEFAULTS[self::KEY_GAMESERVER_DEFAULT_SLOTS];

        return is_numeric($value) ? max(1, (int) $value) : self::DEFAULTS[self::KEY_GAMESERVER_DEFAULT_SLOTS];
    }

    public function getGameserverMinSlots(): int
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_GAMESERVER_MIN_SLOTS] ?? self::DEFAULTS[self::KEY_GAMESERVER_MIN_SLOTS];

        return is_numeric($value) ? max(1, (int) $value) : self::DEFAULTS[self::KEY_GAMESERVER_MIN_SLOTS];
    }

    public function getGameserverMaxSlots(): int
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_GAMESERVER_MAX_SLOTS] ?? self::DEFAULTS[self::KEY_GAMESERVER_MAX_SLOTS];

        return is_numeric($value) ? max(1, (int) $value) : self::DEFAULTS[self::KEY_GAMESERVER_MAX_SLOTS];
    }

    public function isGameserverPortRangeVisible(): bool
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_GAMESERVER_SHOW_PORT_RANGE] ?? self::DEFAULTS[self::KEY_GAMESERVER_SHOW_PORT_RANGE];

        return (bool) $value;
    }

    public function isGameserverStartStopAllowed(): bool
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_GAMESERVER_ALLOW_START_STOP] ?? self::DEFAULTS[self::KEY_GAMESERVER_ALLOW_START_STOP];

        return (bool) $value;
    }

    public function isCustomerDataManagerEnabled(): bool
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_CUSTOMER_DATA_MANAGER_ENABLED] ?? self::DEFAULTS[self::KEY_CUSTOMER_DATA_MANAGER_ENABLED];

        return (bool) $value;
    }

    public function isCustomerFilePushEnabled(): bool
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_CUSTOMER_FILE_PUSH_ENABLED] ?? self::DEFAULTS[self::KEY_CUSTOMER_FILE_PUSH_ENABLED];

        return (bool) $value;
    }

    public function getCustomerConsoleLabel(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_CUSTOMER_CONSOLE_LABEL] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getCustomerLogsLabel(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_CUSTOMER_LOGS_LABEL] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getInstanceBaseDir(): string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_INSTANCE_BASE_DIR] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['EASYWI_INSTANCE_BASE_DIR'] ?? $_SERVER['EASYWI_INSTANCE_BASE_DIR'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return self::DEFAULTS[self::KEY_INSTANCE_BASE_DIR];
    }

    public function getSftpHost(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_SFTP_HOST] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['EASYWI_SFTP_HOST'] ?? $_SERVER['EASYWI_SFTP_HOST'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }

    public function getSftpPort(): int
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_SFTP_PORT] ?? null;

        if (is_numeric($value)) {
            return max(1, (int) $value);
        }

        $env = $_ENV['EASYWI_SFTP_PORT'] ?? $_SERVER['EASYWI_SFTP_PORT'] ?? null;
        if (is_numeric($env)) {
            return max(1, (int) $env);
        }

        return self::DEFAULTS[self::KEY_SFTP_PORT];
    }

    public function getSftpUsername(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_SFTP_USERNAME] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['EASYWI_SFTP_USERNAME'] ?? $_SERVER['EASYWI_SFTP_USERNAME'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }

    public function getSftpPassword(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_SFTP_PASSWORD] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['EASYWI_SFTP_PASSWORD'] ?? $_SERVER['EASYWI_SFTP_PASSWORD'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }

    public function getSftpPrivateKey(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_SFTP_PRIVATE_KEY] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['EASYWI_SFTP_PRIVATE_KEY'] ?? $_SERVER['EASYWI_SFTP_PRIVATE_KEY'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }

    public function getSftpPrivateKeyPath(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_SFTP_PRIVATE_KEY_PATH] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['EASYWI_SFTP_PRIVATE_KEY_PATH'] ?? $_SERVER['EASYWI_SFTP_PRIVATE_KEY_PATH'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }

    public function getSftpPrivateKeyPassphrase(): ?string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_SFTP_PRIVATE_KEY_PASSPHRASE] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $env = $_ENV['EASYWI_SFTP_PRIVATE_KEY_PASSPHRASE'] ?? $_SERVER['EASYWI_SFTP_PRIVATE_KEY_PASSPHRASE'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }

    public function getInvoiceLayout(): string
    {
        $settings = $this->getSettings();
        $value = $settings[self::KEY_INVOICE_LAYOUT] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return self::DEFAULT_INVOICE_LAYOUT;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        $normalized = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = $settings[$key] ?? $default;

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }

            if ($key === self::KEY_INSTANCE_BASE_DIR) {
                $value = is_string($value) && $value !== '' ? $value : self::DEFAULTS[self::KEY_INSTANCE_BASE_DIR];
            }

            if ($key === self::KEY_SFTP_PORT) {
                $value = is_numeric($value) ? max(1, (int) $value) : self::DEFAULTS[self::KEY_SFTP_PORT];
            }

            if (in_array($key, [
                self::KEY_GAMESERVER_DEFAULT_SLOTS,
                self::KEY_GAMESERVER_MIN_SLOTS,
                self::KEY_GAMESERVER_MAX_SLOTS,
            ], true)) {
                $value = is_numeric($value) ? max(1, (int) $value) : $default;
            }

            if (in_array($key, [
                self::KEY_GAMESERVER_SHOW_PORT_RANGE,
                self::KEY_GAMESERVER_ALLOW_START_STOP,
                self::KEY_CUSTOMER_DATA_MANAGER_ENABLED,
                self::KEY_CUSTOMER_FILE_PUSH_ENABLED,
            ], true)) {
                $value = (bool) $value;
            }

            if ($key === self::KEY_MAIL_DEFAULT_LOCALE) {
                $value = is_string($value) && in_array($value, ['de', 'en'], true)
                    ? $value
                    : self::DEFAULTS[self::KEY_MAIL_DEFAULT_LOCALE];
            }

            $normalized[$key] = $value;
        }

        $mailFromNameEnv = $_ENV['MAIL_FROM_NAME'] ?? $_SERVER['MAIL_FROM_NAME'] ?? null;
        $mailFromAddressEnv = $_ENV['MAIL_FROM_ADDRESS'] ?? $_SERVER['MAIL_FROM_ADDRESS'] ?? null;
        $mailReplyToEnv = $_ENV['MAIL_REPLY_TO'] ?? $_SERVER['MAIL_REPLY_TO'] ?? null;

        if ($normalized[self::KEY_MAIL_FROM_NAME] === null && is_string($mailFromNameEnv) && $mailFromNameEnv !== '') {
            $normalized[self::KEY_MAIL_FROM_NAME] = $mailFromNameEnv;
        }
        if ($normalized[self::KEY_MAIL_FROM_ADDRESS] === null && is_string($mailFromAddressEnv) && $mailFromAddressEnv !== '') {
            $normalized[self::KEY_MAIL_FROM_ADDRESS] = $mailFromAddressEnv;
        }
        if ($normalized[self::KEY_MAIL_REPLY_TO] === null && is_string($mailReplyToEnv) && $mailReplyToEnv !== '') {
            $normalized[self::KEY_MAIL_REPLY_TO] = $mailReplyToEnv;
        }
        if ($normalized[self::KEY_SUPPORT_EMAIL] === null && is_string($normalized[self::KEY_MAIL_FROM_ADDRESS] ?? null)) {
            $normalized[self::KEY_SUPPORT_EMAIL] = $normalized[self::KEY_MAIL_FROM_ADDRESS];
        }

        $minSlots = $normalized[self::KEY_GAMESERVER_MIN_SLOTS];
        $maxSlots = $normalized[self::KEY_GAMESERVER_MAX_SLOTS];
        if ($minSlots > $maxSlots) {
            $normalized[self::KEY_GAMESERVER_MIN_SLOTS] = $maxSlots;
            $minSlots = $maxSlots;
        }
        if ($normalized[self::KEY_GAMESERVER_DEFAULT_SLOTS] < $minSlots) {
            $normalized[self::KEY_GAMESERVER_DEFAULT_SLOTS] = $minSlots;
        }
        if ($normalized[self::KEY_GAMESERVER_DEFAULT_SLOTS] > $maxSlots) {
            $normalized[self::KEY_GAMESERVER_DEFAULT_SLOTS] = $maxSlots;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function persistSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $setting = $this->repository->find($key);
            if ($setting === null) {
                $setting = new AppSetting($key, $value);
                $this->entityManager->persist($setting);
                continue;
            }

            $setting->setValue($value);
        }

        $this->entityManager->flush();
    }
}
