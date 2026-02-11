<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegacyAccountRedirectController
{
    #[Route(path: '/profile/security', name: 'legacy_profile_security_redirect', methods: ['GET'])]
    public function security(): Response
    {
        return new RedirectResponse('/account/security', Response::HTTP_FOUND);
    }

    #[Route(path: '/profile/security/{rest}', name: 'legacy_profile_security_redirect_rest', methods: ['GET', 'POST'], requirements: ['rest' => '.+'])]
    public function securitySubpath(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return new RedirectResponse('/account/security', Response::HTTP_FOUND);
        }

        return new RedirectResponse('/account/security', Response::HTTP_SEE_OTHER);
    }
}
