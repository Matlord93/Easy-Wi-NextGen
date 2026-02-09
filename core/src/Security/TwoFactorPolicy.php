<?php

declare(strict_types=1);

namespace App\Security;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;

final class TwoFactorPolicy
{
    public function __construct(private readonly AppSettingsService $settingsService)
    {
    }

    public function isRequired(User $user): bool
    {
        $settings = $this->settingsService->getSettings();
        if (($settings[AppSettingsService::KEY_SECURITY_2FA_GLOBAL_REQUIRED] ?? false) === true) {
            return true;
        }

        return match ($user->getType()) {
            UserType::Admin, UserType::Superadmin => (bool) ($settings[AppSettingsService::KEY_SECURITY_2FA_ADMIN_REQUIRED] ?? false),
            UserType::Reseller => (bool) ($settings[AppSettingsService::KEY_SECURITY_2FA_RESELLER_REQUIRED] ?? false),
            default => (bool) ($settings[AppSettingsService::KEY_SECURITY_2FA_CUSTOMER_REQUIRED] ?? false),
        };
    }
}
