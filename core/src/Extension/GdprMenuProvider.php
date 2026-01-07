<?php

declare(strict_types=1);

namespace App\Extension;

final class GdprMenuProvider implements ExtensionMenuProviderInterface
{
    public function adminMenuItems(): array
    {
        return [];
    }

    public function customerMenuItems(): array
    {
        return [
            new MenuItem(
                'GDPR Consent',
                '/gdpr/consents',
                '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a6 6 0 0 1 6 6v2.4a3.6 3.6 0 0 1-2.1 3.25l-3.9 1.73-3.9-1.73A3.6 3.6 0 0 1 4 10.4V8a6 6 0 0 1 6-6Zm0 1.5A4.5 4.5 0 0 0 5.5 8v2.4a2.1 2.1 0 0 0 1.22 1.89L10 13.78l3.28-1.45a2.1 2.1 0 0 0 1.22-1.89V8A4.5 4.5 0 0 0 10 3.5Z"/></svg>'
            ),
        ];
    }
}
