<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\UI\Controller\Admin;

use App\Module\Core\Dto\Ts3\InstallDto;
use App\Module\Core\Dto\Ts3\Ts3NodeDto;
use App\Module\Core\Domain\Entity\Ts3Node;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Form\Ts3NodeType;
use App\Repository\AgentRepository;
use App\Repository\Ts3NodeRepository;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Ts3\Ts3NodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/admin/ts3/nodes')]
final class AdminTs3NodeController
{
    public function __construct(
        private readonly Ts3NodeRepository $nodeRepository,
        private readonly AgentRepository $agentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
        private readonly Ts3NodeService $nodeService,
        private readonly FormFactoryInterface $formFactory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_ts3_nodes_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request);

        $nodes = $this->nodeRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/ts3/nodes/index.html.twig', [
            'activeNav' => 'ts3',
            'nodes' => $nodes,
        ]));
    }

    #[Route(path: '/new', name: 'admin_ts3_nodes_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin($request);

        $dto = new Ts3NodeDto();
        $form = $this->formFactory->create(Ts3NodeType::class, $dto, [
            'agent_choices' => $this->buildAgentChoices(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyAgentDefaults($dto, $form);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $node = new Ts3Node(
                $dto->name,
                rtrim($dto->agentBaseUrl, '/'),
                $this->crypto->encrypt($dto->agentApiToken),
                $dto->downloadUrl,
                $dto->installPath,
                $dto->instanceName,
                $dto->serviceName,
            );
            $node->setQueryBindIp($dto->queryBindIp);
            $node->setQueryPort($dto->queryPort);
            $this->entityManager->persist($node);
            $this->entityManager->flush();

            $request->getSession()->getFlashBag()->add('success', 'TS3 node created.');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => sprintf('/admin/ts3/nodes/%d', $node->getId()),
            ]);
        }

        return new Response($this->twig->render('admin/ts3/nodes/new.html.twig', [
            'activeNav' => 'ts3',
            'form' => $form->createView(),
        ]));
    }

    #[Route(path: '/{id}', name: 'admin_ts3_nodes_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $this->requireAdmin($request);

        $node = $this->findNode($id);

        return new Response($this->twig->render('admin/ts3/nodes/show.html.twig', [
            'activeNav' => 'ts3',
            'node' => $node,
            'admin_password' => null,
            'csrf' => $this->csrfTokens($node),
        ]));
    }

    #[Route(path: '/{id}/install', name: 'admin_ts3_nodes_install', methods: ['POST'])]
    public function install(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts3_install_' . $id);

        $dto = new InstallDto(
            $node->getDownloadUrl(),
            $node->getInstallPath(),
            $node->getInstanceName(),
            $node->getServiceName(),
            true,
            $node->getQueryBindIp(),
            $node->getQueryPort(),
            null,
        );

        $this->nodeService->install($node, $dto);
        $request->getSession()->getFlashBag()->add('success', 'TS3 install queued.');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/show-admin-credentials', name: 'admin_ts3_nodes_show_admin_credentials', methods: ['POST'])]
    public function showAdminCredentials(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts3_show_admin_' . $id);

        $adminPassword = $node->getAdminPassword($this->crypto);
        if ($adminPassword !== null) {
            $node->markAdminPasswordShown();
            $this->entityManager->flush();
        }

        return new Response($this->twig->render('admin/ts3/nodes/show.html.twig', [
            'activeNav' => 'ts3',
            'node' => $node,
            'admin_password' => $adminPassword,
            'csrf' => $this->csrfTokens($node),
        ]));
    }

    #[Route(path: '/{id}/start', name: 'admin_ts3_nodes_start', methods: ['POST'])]
    public function start(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts3_start_' . $id);

        $this->nodeService->start($node);
        $request->getSession()->getFlashBag()->add('success', 'TS3 service started.');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/stop', name: 'admin_ts3_nodes_stop', methods: ['POST'])]
    public function stop(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts3_stop_' . $id);

        $this->nodeService->stop($node);
        $request->getSession()->getFlashBag()->add('success', 'TS3 service stopped.');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/restart', name: 'admin_ts3_nodes_restart', methods: ['POST'])]
    public function restart(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts3_restart_' . $id);

        $this->nodeService->restart($node);
        $request->getSession()->getFlashBag()->add('success', 'TS3 service restarted.');

        return $this->redirectToNode($node);
    }

    private function requireAdmin(Request $request): User
    {
        $actor = $request->attributes->get('current_user');
        if (!$actor instanceof User || !$actor->isAdmin()) {
            throw new UnauthorizedHttpException('session', 'Unauthorized.');
        }

        return $actor;
    }

    private function findNode(int $id): Ts3Node
    {
        $node = $this->nodeRepository->find($id);
        if ($node === null) {
            throw new NotFoundHttpException('TS3 node not found.');
        }

        return $node;
    }

    private function redirectToNode(Ts3Node $node): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => sprintf('/admin/ts3/nodes/%d', $node->getId()),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function csrfTokens(Ts3Node $node): array
    {
        $id = (int) $node->getId();

        return [
            'install' => $this->csrfTokenManager->getToken('ts3_install_' . $id)->getValue(),
            'show_admin' => $this->csrfTokenManager->getToken('ts3_show_admin_' . $id)->getValue(),
            'start' => $this->csrfTokenManager->getToken('ts3_start_' . $id)->getValue(),
            'stop' => $this->csrfTokenManager->getToken('ts3_stop_' . $id)->getValue(),
            'restart' => $this->csrfTokenManager->getToken('ts3_restart_' . $id)->getValue(),
        ];
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $token))) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildAgentChoices(): array
    {
        $choices = [];
        $agents = $this->agentRepository->findBy([], ['name' => 'ASC']);

        foreach ($agents as $agent) {
            $label = $agent->getName() !== null && $agent->getName() !== ''
                ? sprintf('%s (%s)', $agent->getName(), $agent->getId())
                : $agent->getId();
            $choices[$label] = $agent->getId();
        }

        return $choices;
    }

    private function applyAgentDefaults(Ts3NodeDto $dto, \Symfony\Component\Form\FormInterface $form): void
    {
        $agentId = trim($dto->agentNodeId);
        if ($agentId !== '') {
            $agent = $this->agentRepository->find($agentId);
            if ($agent === null) {
                $form->addError(new FormError('Selected agent was not found.'));

                return;
            }

            if (trim($dto->agentBaseUrl) === '') {
                $dto->agentBaseUrl = $agent->getServiceBaseUrl();
            }
            if (trim($dto->agentApiToken) === '') {
                $dto->agentApiToken = $agent->getServiceApiToken($this->crypto);
            }
        }

        if (trim($dto->installPath) === '') {
            $dto->installPath = '/home/teamspeak3';
        }

        if (trim($dto->agentBaseUrl) === '') {
            $form->addError(new FormError('Agent Base URL is required.'));
        }
        if (trim($dto->agentApiToken) === '') {
            $form->addError(new FormError('Agent API Token is required.'));
        }
    }
}
