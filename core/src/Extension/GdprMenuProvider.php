<?php

declare(strict_types=1);

namespace App\Extension;

final class GdprMenuProvider implements ExtensionMenuProviderInterface
{
    public function adminMenuItems(): array
    {
        return [
            new MenuItem(
                'GDPR Retention',
                '/admin/gdpr/retention',
                '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4.75 3A1.75 1.75 0 0 0 3 4.75v10.5C3 16.216 3.784 17 4.75 17h10.5A1.75 1.75 0 0 0 17 15.25V7.5a.75.75 0 0 0-.75-.75h-4.5A1.25 1.25 0 0 1 10.5 5.5V3.75A.75.75 0 0 0 9.75 3h-5Zm2.5 6.25a.75.75 0 0 1 .75-.75h4.5a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75Zm0 3a.75.75 0 0 1 .75-.75h3.5a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75Z"/></svg>'
            ),
        ];
    }

    public function customerMenuItems(): array
    {
        return [
            new MenuItem(
                'GDPR Consent',
                '/gdpr/consents',
                '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a6 6 0 0 1 6 6v2.4a3.6 3.6 0 0 1-2.1 3.25l-3.9 1.73-3.9-1.73A3.6 3.6 0 0 1 4 10.4V8a6 6 0 0 1 6-6Zm0 1.5A4.5 4.5 0 0 0 5.5 8v2.4a2.1 2.1 0 0 0 1.22 1.89L10 13.78l3.28-1.45a2.1 2.1 0 0 0 1.22-1.89V8A4.5 4.5 0 0 0 10 3.5Z"/></svg>'
            ),
            new MenuItem(
                'GDPR Export',
                '/gdpr/exports',
                '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3a.75.75 0 0 1 .75.75v6.69l2.22-2.22a.75.75 0 1 1 1.06 1.06l-3.5 3.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 1 1 1.06-1.06l2.22 2.22V3.75A.75.75 0 0 1 10 3Zm-6 11.25a.75.75 0 0 1 .75-.75h10.5a.75.75 0 0 1 0 1.5H4.75a.75.75 0 0 1-.75-.75Z"/></svg>'
            ),
            new MenuItem(
                'GDPR Delete',
                '/gdpr/delete',
                '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7.5 3.5a.75.75 0 0 1 .75-.75h3.5a.75.75 0 0 1 .75.75V5h3a.75.75 0 0 1 0 1.5h-.69l-.68 9.08A2.75 2.75 0 0 1 11.4 18H8.6a2.75 2.75 0 0 1-2.74-2.42L5.18 6.5H4.5a.75.75 0 0 1 0-1.5h3V3.5Zm1.75.75V5h1.5V4.25h-1.5Zm-1.78 2.25.62 8.3a1.25 1.25 0 0 0 1.25 1.2h2.8a1.25 1.25 0 0 0 1.25-1.2l.62-8.3H7.47Z"/></svg>'
            ),
        ];
    }
}
