<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Public;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PanelLandingController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/customer', name: 'customer_panel_landing', methods: ['GET'])]
    public function customer(Request $request): Response
    {
        $user = $request->attributes->get('current_user');
        if (!$user instanceof User || $user->getType() !== UserType::Customer) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        return new RedirectResponse('/dashboard');
    }

    #[Route(path: '/reseller', name: 'reseller_panel_landing', methods: ['GET'])]
    public function reseller(Request $request): Response
    {
        $user = $request->attributes->get('current_user');
        if (!$user instanceof User || $user->getType() !== UserType::Reseller) {
            return new Response($this->translator->trans('error_forbidden'), Response::HTTP_FORBIDDEN);
        }

        return new RedirectResponse('/reseller/customers');
    }
}
