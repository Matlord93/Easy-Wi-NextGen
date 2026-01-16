<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Ts6\AdminCreateVirtualServerDto;
use App\Dto\Ts6\CreateVirtualServerDto;
use App\Entity\User;
use App\Form\Ts6AdminCreateVirtualServerType;
use App\Repository\Ts6NodeRepository;
use App\Repository\UserRepository;
use App\Service\Ts6\Ts6VirtualServerService;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/admin/ts6/servers')]
final class AdminTs6ServerController
{
    public function __construct(
        private readonly Ts6NodeRepository $nodeRepository,
        private readonly UserRepository $userRepository,
        private readonly Ts6VirtualServerService $virtualServerService,
        private readonly FormFactoryInterface $formFactory,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '/create', name: 'admin_ts6_servers_create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $this->requireAdmin($request);

        $customers = $this->userRepository->findCustomers();
        $nodes = $this->nodeRepository->findBy([], ['name' => 'ASC']);

        $customerChoices = [];
        foreach ($customers as $customer) {
            $customerChoices[$customer->getEmail()] = $customer->getId() ?? 0;
        }

        $nodeChoices = [];
        foreach ($nodes as $node) {
            $nodeChoices[$node->getName()] = $node->getId() ?? 0;
        }

        $dto = new AdminCreateVirtualServerDto();
        $form = $this->formFactory->create(Ts6AdminCreateVirtualServerType::class, $dto, [
            'customer_choices' => $customerChoices,
            'node_choices' => $nodeChoices,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $customer = $this->userRepository->find($dto->customerId);
            $node = $this->nodeRepository->find($dto->nodeId);

            if ($customer === null || $node === null) {
                throw new BadRequestHttpException('Customer or node not found.');
            }

            $createDto = new CreateVirtualServerDto($dto->name, $dto->slots, $dto->voicePort);
            $this->virtualServerService->createForCustomer($customer->getId() ?? 0, $node, $createDto);
            $request->getSession()->getFlashBag()->add('success', 'TS6 virtual server created.');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => '/admin/ts6/servers/create',
            ]);
        }

        return new Response($this->twig->render('admin/ts6/servers/create.html.twig', [
            'activeNav' => 'ts6',
            'form' => $form->createView(),
        ]));
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }
}
