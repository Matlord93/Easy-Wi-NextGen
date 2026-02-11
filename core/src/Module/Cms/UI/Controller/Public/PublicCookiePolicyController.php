<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCookiePolicyController
{
    #[Route(path: '/cookie-richtlinie', name: 'public_cookie_policy', methods: ['GET'], priority: 10)]
    public function __invoke(): Response
    {
        return new RedirectResponse('/cookies', Response::HTTP_MOVED_PERMANENTLY);
    }
}
