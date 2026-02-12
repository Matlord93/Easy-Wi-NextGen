<?php

declare(strict_types=1);

namespace App\Module\Voice\UI\Controller\Customer;

use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Repository\VoiceInstanceRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route('/customer/voice')]
final class CustomerVoiceController
{
    public function __construct(
        private readonly VoiceInstanceRepository $repository,
        private readonly Environment $twig,
    ) {
    }

    #[Route('', name: 'customer_voice', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || $actor->getType() !== UserType::Customer) {
            return new Response('Unauthorized.', 401);
        }

        $instances = $this->repository->findByCustomer($actor, 200);

        return new Response($this->twig->render('customer/voice/index.html.twig', [
            'activeNav' => 'voice',
            'instances' => $instances,
        ]));
    }
}
