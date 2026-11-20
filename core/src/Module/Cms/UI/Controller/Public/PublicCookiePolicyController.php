<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Cms\Application\CmsSiteResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PublicCookiePolicyController
{
    public function __construct(private CmsSiteResolver $sites, private TranslatorInterface $translator)
    {
    }

    #[Route('/cookies', name: 'public_cookie_policy', methods: ['GET'], priority: 12)]
    #[Route('/cookie-richtlinie', name: 'public_cookie_policy_legacy', methods: ['GET'], priority: 12)]
    #[Route('/cookie-preferences', name: 'public_cookie_preferences', methods: ['GET'], priority: 12)]
    #[Route('/cookie-preferenzen', name: 'public_cookie_preferences_de', methods: ['GET'], priority: 12)]
    #[Route('/cookie-einstellungen', name: 'public_cookie_settings', methods: ['GET'], priority: 12)]
    public function index(Request $request): Response
    {
        if (null === $this->sites->resolve($request)) {
            return new Response($this->translator->trans('error_not_found'), Response::HTTP_NOT_FOUND);
        }
        $policy = in_array($request->getPathInfo(), ['/cookies', '/cookie-richtlinie'], true);
        $language = $request->query->getString('lang');
        $url = '/'.(in_array($language, ['de', 'en'], true) ? '?lang='.$language : '');

        return new RedirectResponse($url.($policy ? '#cookie-policy' : '#cookie-settings'));
    }
}
