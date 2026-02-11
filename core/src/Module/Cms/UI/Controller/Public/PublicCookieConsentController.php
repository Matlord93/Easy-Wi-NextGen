<?php

declare(strict_types=1);

namespace App\Module\Cms\UI\Controller\Public;

use App\Module\Core\Application\CookieConsentService;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCookieConsentController
{
    public function __construct(private readonly CookieConsentService $cookieConsentService)
    {
    }

    #[Route(path: '/cookie-consent', name: 'public_cookie_consent_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $consent = $this->cookieConsentService->createFromFlags(
            filter_var($payload['statistics'] ?? false, FILTER_VALIDATE_BOOL),
            filter_var($payload['marketing'] ?? false, FILTER_VALIDATE_BOOL),
        );

        $response = new JsonResponse([
            'ok' => true,
            'consent' => $consent,
            'cookie' => CookieConsentService::COOKIE_NAME,
        ]);

        $response->headers->setCookie(
            Cookie::create(CookieConsentService::COOKIE_NAME, $this->cookieConsentService->encode($consent))
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(false)
                ->withSameSite('lax')
                ->withExpires((new \DateTimeImmutable())->modify('+12 months'))
        );

        return $response;
    }
}
