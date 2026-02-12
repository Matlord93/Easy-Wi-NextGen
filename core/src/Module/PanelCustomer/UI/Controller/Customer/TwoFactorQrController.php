<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Application\AppSettingsService;
use App\Module\Core\Application\QrCodeService;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\TwoFactorService;
use App\Module\Core\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TwoFactorQrController
{
    public function __construct(
        private readonly TwoFactorService $twoFactorService,
        private readonly AppSettingsService $settings,
        private readonly SecretsCrypto $secretsCrypto,
        private readonly QrCodeService $qrCodeService,
    ) {
    }

    #[Route(path: '/2fa/qr', name: 'user_2fa_qr', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $request->attributes->get('current_user');
        if (!$user instanceof User) {
            return new Response('Forbidden.', Response::HTTP_FORBIDDEN);
        }

        $secret = $user->getTotpSecret($this->secretsCrypto);
        if ($secret === null) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND);
        }

        $otp = $this->twoFactorService->getOtpAuthUri($this->settings->getBrandingName(), $user->getEmail(), $secret);
        $image = $this->qrCodeService->renderImage($otp);

        $response = new Response($image['content'], Response::HTTP_OK, ['Content-Type' => $image['mimeType']]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
