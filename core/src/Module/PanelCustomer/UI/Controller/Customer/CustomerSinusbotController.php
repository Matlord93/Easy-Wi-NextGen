<?php

declare(strict_types=1);

namespace App\Module\PanelCustomer\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\SinusbotInstanceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/customer/infrastructure/sinusbot')]
final class CustomerSinusbotController
{
    public function __construct(
        private readonly SinusbotInstanceRepository $instanceRepository,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_sinusbot_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);
        $instances = $this->instanceRepository->findBy(
            ['customerId' => $customer->getId(), 'archivedAt' => null],
            ['updatedAt' => 'DESC'],
        );

        return new Response($this->twig->render('customer/infrastructure/sinusbot/index.html.twig', [
            'activeNav' => 'sinusbot',
            'instances' => $instances,
        ]));
    }

    private function requireCustomer(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }
}
