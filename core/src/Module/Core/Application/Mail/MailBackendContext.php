<?php

declare(strict_types=1);

namespace App\Module\Core\Application\Mail;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Enum\MailBackend;

final class MailBackendContext
{
    public function __construct(private readonly AppSettingsService $settings)
    {
    }

    public function isMailEnabled(): bool
    {
        $settings = $this->settings->getSettings();

        return (bool) ($settings[AppSettingsService::KEY_MAIL_ENABLED] ?? true);
    }

    public function backend(): string
    {
        $settings = $this->settings->getSettings();

        return MailBackend::normalize($settings[AppSettingsService::KEY_MAIL_BACKEND] ?? MailBackend::Local->value)->value;
    }

    public function operationsAllowed(): bool
    {
        return $this->isMailEnabled() && $this->backend() !== 'none';
    }

    /**
     * @return array{error: string, error_code: string, ui_hint: string, mail_enabled: bool, mail_backend: string}|null
     */
    public function blockedResponsePayload(string $resource): ?array
    {
        if ($this->operationsAllowed()) {
            return null;
        }

        return [
            'error' => sprintf('%s operations are disabled for the active mail backend.', ucfirst($resource)),
            'error_code' => 'MAIL_BACKEND_DISABLED',
            'ui_hint' => 'Enable mail and select a backend (local|panel|external) in mail platform settings.',
            'mail_enabled' => $this->isMailEnabled(),
            'mail_backend' => $this->backend(),
        ];
    }
}
