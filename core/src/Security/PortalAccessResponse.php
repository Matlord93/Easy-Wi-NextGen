<?php

declare(strict_types=1);

namespace App\Security;

use App\Infrastructure\Config\ScalarValue;
use App\Module\Core\Application\PortalLocale;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class PortalAccessResponse
{
    public function __construct(private TranslatorInterface $translator, private Environment $twig)
    {
    }

    public function signIn(Request $request, ?string $checkoutTarget = null): Response
    {
        $adminCookie = $request->cookies->has(SessionAuthenticator::ADMIN_SESSION_COOKIE);
        $customerCookie = $request->cookies->has(SessionAuthenticator::CUSTOMER_SESSION_COOKIE);
        $adminArea = str_starts_with($request->getPathInfo().'/', '/admin/');
        $restricted = 1 === preg_match('~^/(?:api/(?:v1/)?)?(?:admin|reseller)(?:/|$)~D', $request->getPathInfo());
        $previousLogin = !$adminCookie && !$customerCookie && $request->hasPreviousSession() && $request->getSession()->has('last_login_at');
        $expired = $adminCookie || (!$restricted && $customerCookie) || $previousLogin;
        $target = PostLoginRedirectResolver::localTarget($checkoutTarget ?? ($request->isMethodSafe()
            ? $request->getRequestUri()
            : $request->getBaseUrl().$request->getPathInfo()));
        if (null === $checkoutTarget && $this->expectsJson($request)) {
            // A request for a fragment or API endpoint should return to its page.
            $target = $this->pageTarget($request);
        }
        $query = null === $target ? '' : '?target='.rawurlencode($target);
        $login = '/login'.$query;
        if ($this->expectsJson($request) || ($adminArea && !$expired)) {
            return $this->problem($request, Response::HTTP_UNAUTHORIZED, $expired ? 'session_expired' : 'login_required', $login);
        }

        return new RedirectResponse(
            $expired ? '/session-expired'.$query : $login,
            Response::HTTP_SEE_OTHER,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    /** @param array<array-key, mixed> $headers */
    public function problem(Request $request, int $status, string $code, ?string $destination = null, array $headers = []): Response
    {
        $locale = PortalLocale::normalize($request->query->all()['lang'] ?? null)
            ?? PortalLocale::normalize($request->hasSession() ? $request->getSession()->get('portal_language') : null)
            ?? PortalLocale::normalize($request->cookies->get('portal_language'))
            ?? $request->getLocale();
        $request->setLocale($locale);
        $title = $this->translator->trans('portal_problem_'.$code.'_title', [], 'portal', $locale);
        $message = $this->translator->trans('portal_problem_'.$code.'_message', [], 'portal', $locale);
        $action = null === $destination ? null : $this->translator->trans('portal_problem_'.$code.'_action', [], 'portal', $locale);
        $responseHeaders = ['Cache-Control' => 'private, no-store', 'X-EasyWi-Problem' => $code];
        foreach (['Allow', 'Retry-After'] as $header) {
            $value = ScalarValue::text($headers[$header] ?? $headers[strtolower($header)] ?? null);
            if ('' !== $value) {
                $responseHeaders[$header] = $value;
            }
        }

        if ($this->expectsJson($request)) {
            return new JsonResponse([
                'error' => $message,
                'code' => strtoupper($code),
                'action' => $action,
                'action_url' => $destination,
            ], $status, $responseHeaders);
        }

        return new Response($this->twig->render('public/auth/access_problem.html.twig', [
            'locale' => $locale,
            'title' => $title,
            'message' => $message,
            'action' => $action,
            'destination' => $destination,
        ]), $status, $responseHeaders);
    }

    private function expectsJson(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/')
            || $request->attributes->get('_route') === 'customer_files_health'
            || $request->isXmlHttpRequest()
            || $request->headers->get('HX-Request') === 'true'
            || $request->headers->get('Sec-Fetch-Dest') === 'empty'
            || $request->getPreferredFormat() === 'json';
    }

    private function pageTarget(Request $request): ?string
    {
        $referer = $request->headers->get('Referer', '');
        if (str_starts_with($referer, $request->getSchemeAndHttpHost().'/')) {
            return PostLoginRedirectResolver::localTarget(substr($referer, strlen($request->getSchemeAndHttpHost())));
        }

        return null;
    }
}
