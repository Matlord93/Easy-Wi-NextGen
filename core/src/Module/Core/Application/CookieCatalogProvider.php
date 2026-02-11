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
                'purpose' => 'Authentifizierung für Admin/Backend-Sitzungen.',
                'category' => 'notwendig',
                'duration' => '30 Tage',
                'provider' => 'Easy-WI (First-Party)',
            ],
            [
                'name' => 'easywi_customer_session',
                'purpose' => 'Authentifizierung für Customer-Bereich-Sitzungen.',
                'category' => 'notwendig',
                'duration' => '30 Tage',
                'provider' => 'Easy-WI (First-Party)',
            ],
            [
                'name' => 'PHPSESSID',
                'purpose' => 'Temporäre Session für Formulare/CSRF/Flash-Messages.',
                'category' => 'notwendig',
                'duration' => 'Session',
                'provider' => 'Easy-WI / Symfony Session (First-Party)',
            ],
            [
                'name' => CookieConsentService::COOKIE_NAME,
                'purpose' => 'Speichert Cookie-Einwilligung (Version + Kategorien).',
                'category' => 'notwendig',
                'duration' => '12 Monate',
                'provider' => 'Easy-WI (First-Party)',
            ],
            [
                'name' => 'portal_language',
                'purpose' => 'Speichert Sprachpräferenz der öffentlichen Seite.',
                'category' => 'notwendig',
                'duration' => '12 Monate',
                'provider' => 'Easy-WI (First-Party)',
            ],
        ];
    }
}
