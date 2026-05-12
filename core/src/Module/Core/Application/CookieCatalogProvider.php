<?php

declare(strict_types=1);

namespace App\Module\Core\Application;

final class CookieCatalogProvider
{
    /**
     * @return list<array{name:string, purpose:string, category:string, duration:string, provider:string}>
     */
    public function all(): array
    {
        return [
            [
                'name' => 'easywi_session',
                'purpose' => 'cookie_purpose_admin_session',
                'category' => 'cookie_category_necessary_key',
                'duration' => 'cookie_duration_30_days',
                'provider' => 'Easy-WI (First-Party)',
            ],
            [
                'name' => 'easywi_customer_session',
                'purpose' => 'cookie_purpose_customer_session',
                'category' => 'cookie_category_necessary_key',
                'duration' => 'cookie_duration_30_days',
                'provider' => 'Easy-WI (First-Party)',
            ],
            [
                'name' => 'PHPSESSID',
                'purpose' => 'cookie_purpose_temporary_session_csrf',
                'category' => 'cookie_category_necessary_key',
                'duration' => 'cookie_duration_session_key',
                'provider' => 'Easy-WI / Symfony Session (First-Party)',
            ],
            [
                'name' => CookieConsentService::COOKIE_NAME,
                'purpose' => 'cookie_purpose_consent_versioned',
                'category' => 'cookie_category_necessary_key',
                'duration' => 'cookie_duration_12_months',
                'provider' => 'Easy-WI (First-Party)',
            ],
            [
                'name' => 'portal_language',
                'purpose' => 'cookie_purpose_portal_language',
                'category' => 'cookie_category_necessary_key',
                'duration' => 'cookie_duration_12_months',
                'provider' => 'Easy-WI (First-Party)',
            ],
        ];
    }
}
