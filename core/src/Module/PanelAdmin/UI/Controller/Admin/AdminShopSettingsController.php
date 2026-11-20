<?php

declare(strict_types=1);

namespace App\Module\PanelAdmin\UI\Controller\Admin;

use App\Infrastructure\Http\RequestActor;
use App\Module\Core\Application\AuditLoggerInterface;
use App\Module\Core\Application\Billing\PaymentSettings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class AdminShopSettingsController
{
    public function __construct(private PaymentSettings $settings, private CsrfTokenManagerInterface $csrf, private AuditLoggerInterface $audit, private Environment $twig)
    {
    }

    #[Route('/admin/shop/settings', name: 'admin_shop_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $actor = RequestActor::user($request);
        if (null === $actor || !$actor->isAdmin()) {
            return new Response('Forbidden.', 403);
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken('shop-settings', $request->request->getString('_token')))) {
                return new Response('Invalid CSRF token.', 403);
            }
            try {
                $this->settings->save($request->request->all());
                $this->audit->log($actor, 'shop.payment_settings.updated', []);

                return new RedirectResponse('/admin/shop/settings?saved=1', 303);
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return new Response($this->twig->render('admin/shop/settings.html.twig', ['activeNav' => 'shop-settings', 'settings' => $this->settings->publicSettings(), 'error' => $error]), null !== $error ? 422 : 200);
    }
}
