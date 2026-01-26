<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\UI\Controller\Admin;

use App\Module\Core\Dto\Ts6\AdminCreateVirtualServerDto;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Application\VirtualServerDtoFactory;
use App\Module\Core\Form\Ts6AdminCreateVirtualServerType;
use App\Repository\Ts6VirtualServerRepository;
use App\Repository\Ts6NodeRepository;
use App\Repository\UserRepository;
use App\Module\Core\Application\Ts6\Ts6VirtualServerService;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/admin/ts6/servers')]
final class AdminTs6ServerController
{
    public function __construct(
        private readonly Ts6NodeRepository $nodeRepository,
        private readonly UserRepository $userRepository,
        private readonly Ts6VirtualServerRepository $virtualServerRepository,
        private readonly Ts6VirtualServerService $virtualServerService,
        private readonly FormFactoryInterface $formFactory,
        private readonly VirtualServerDtoFactory $dtoFactory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_ts6_servers_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request);

        $servers = $this->virtualServerRepository->findBy([], ['updatedAt' => 'DESC']);
        $customers = $this->userRepository->findCustomers();

        $customerMap = [];
        foreach ($customers as $customer) {
            if ($customer->getId() !== null) {
                $customerMap[$customer->getId()] = $customer;
            }
        }

        $csrf = [];
        foreach ($servers as $server) {
            if ($server->getId() !== null) {
                $csrf[$server->getId()] = $this->csrfTokenManager->getToken('ts6_virtual_assign_' . $server->getId())->getValue();
            }
        }

        return new Response($this->twig->render('admin/ts6/servers/index.html.twig', [
            'activeNav' => 'ts6',
            'servers' => $servers,
            'customers' => $customers,
            'customerMap' => $customerMap,
            'csrf' => $csrf,
        ]));
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

            $createDto = $this->dtoFactory->createTs6($dto);
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

    #[Route(path: '/{id}/assign', name: 'admin_ts6_servers_assign', methods: ['POST'])]
    public function assign(Request $request, int $id): Response
    {
        $this->requireAdmin($request);

        $server = $this->virtualServerRepository->find($id);
        if ($server === null) {
            throw new BadRequestHttpException('Virtual server not found.');
        }

        $this->validateCsrf($request, 'ts6_virtual_assign_' . $id);
        $customerId = (int) $request->request->get('customer_id', 0);
        $customer = $this->userRepository->find($customerId);
        if ($customer === null || !$customer->isCustomer()) {
            throw new BadRequestHttpException('Customer not found.');
        }

        $server->setCustomerId($customerId);
        $this->virtualServerRepository->getEntityManager()->flush();
        $request->getSession()->getFlashBag()->add('success', 'TS6 virtual server assigned.');

        return new Response('', Response::HTTP_FOUND, [
            'Location' => '/admin/ts6/servers',
        ]);
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function validateCsrf(Request $request, string $id): void
    {
        $token = new CsrfToken($id, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            throw new BadRequestHttpException('Invalid CSRF token.');
        }
    }
}
