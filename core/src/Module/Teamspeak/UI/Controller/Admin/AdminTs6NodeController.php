<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\UI\Controller\Admin;

use App\Module\Core\Dto\Ts6\InstallDto;
use App\Module\Core\Dto\Ts6\Ts6NodeDto;
use App\Module\Core\Domain\Entity\Ts6Node;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Form\Ts6NodeType;
use App\Repository\AgentRepository;
use App\Repository\AgentJobRepository;
use App\Repository\Ts6NodeRepository;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Ts6\Ts6NodeService;
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

#[Route(path: '/admin/ts6/nodes')]
final class AdminTs6NodeController
{
    public function __construct(
        private readonly Ts6NodeRepository $nodeRepository,
        private readonly AgentRepository $agentRepository,
        private readonly AgentJobRepository $agentJobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretsCrypto $crypto,
        private readonly Ts6NodeService $nodeService,
        private readonly FormFactoryInterface $formFactory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'admin_ts6_nodes_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireAdmin($request);

        $nodes = $this->nodeRepository->findBy([], ['updatedAt' => 'DESC']);

        return new Response($this->twig->render('admin/ts6/nodes/index.html.twig', [
            'activeNav' => 'ts-nodes',
            'nodes' => $nodes,
        ]));
    }

    #[Route(path: '/new', name: 'admin_ts6_nodes_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin($request);

        $dto = new Ts6NodeDto();
        $form = $this->formFactory->create(Ts6NodeType::class, $dto, [
            'agent_choices' => $this->buildAgentChoices(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->applyAgentDefaults($dto, $form);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $agent = $this->agentRepository->find($dto->agentNodeId);
            if ($agent === null) {
                $form->addError(new FormError('Selected agent was not found.'));
                return new Response($this->twig->render('admin/ts6/nodes/new.html.twig', [
                    'activeNav' => 'ts-nodes',
                    'form' => $form->createView(),
                ]), Response::HTTP_BAD_REQUEST);
            }

            $node = new Ts6Node(
                $dto->name,
                $agent,
                rtrim($dto->agentBaseUrl, '/'),
                $this->crypto->encrypt($dto->agentApiToken),
                $dto->downloadUrl,
                $dto->installPath,
                $dto->instanceName,
                $dto->serviceName,
            );
            $node->setOsType($dto->osType);
            $node->setQueryBindIp($dto->queryBindIp);
            $node->setQueryHttpsPort($dto->queryHttpsPort);
            $node->setVoicePort($dto->voicePort);
            $this->entityManager->persist($node);
            $this->entityManager->flush();

            $this->nodeService->install($node, $this->buildInstallDto($node));
            $request->getSession()->getFlashBag()->add('success', 'TS6 node created. Install job queued.');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => sprintf('/admin/ts6/nodes/%d', $node->getId()),
            ]);
        }

        return new Response($this->twig->render('admin/ts6/nodes/new.html.twig', [
            'activeNav' => 'ts-nodes',
            'form' => $form->createView(),
        ]));
    }

    #[Route(path: '/{id}', name: 'admin_ts6_nodes_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $this->requireAdmin($request);

        $node = $this->findNode($id);

        return new Response($this->twig->render('admin/ts6/nodes/show.html.twig', [
            'activeNav' => 'ts-nodes',
            'node' => $node,
            'admin_password' => null,
            'agent_jobs' => $this->loadAgentJobs($node),
            'csrf' => $this->csrfTokens($node),
        ]));
    }

    #[Route(path: '/{id}/install', name: 'admin_ts6_nodes_install', methods: ['POST'])]
    public function install(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts6_install_' . $id);

        $this->nodeService->install($node, $this->buildInstallDto($node));
        $request->getSession()->getFlashBag()->add('success', 'TS6-Installation eingereiht. (TS6 install queued.)');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/show-admin-credentials', name: 'admin_ts6_nodes_show_admin_credentials', methods: ['POST'])]
    public function showAdminCredentials(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts6_show_admin_' . $id);

        $adminPassword = $node->getAdminPassword($this->crypto);
        if ($adminPassword !== null) {
            $node->markAdminPasswordShown();
            $this->entityManager->flush();
        }

        return new Response($this->twig->render('admin/ts6/nodes/show.html.twig', [
            'activeNav' => 'ts-nodes',
            'node' => $node,
            'admin_password' => $adminPassword,
            'agent_jobs' => $this->loadAgentJobs($node),
            'csrf' => $this->csrfTokens($node),
        ]));
    }

    #[Route(path: '/{id}/start', name: 'admin_ts6_nodes_start', methods: ['POST'])]
    public function start(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts6_start_' . $id);

        $this->nodeService->start($node);
        $request->getSession()->getFlashBag()->add('success', 'TS6 service started.');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/stop', name: 'admin_ts6_nodes_stop', methods: ['POST'])]
    public function stop(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts6_stop_' . $id);

        $this->nodeService->stop($node);
        $request->getSession()->getFlashBag()->add('success', 'TS6 service stopped.');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/restart', name: 'admin_ts6_nodes_restart', methods: ['POST'])]
    public function restart(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts6_restart_' . $id);

        $this->nodeService->restart($node);
        $request->getSession()->getFlashBag()->add('success', 'TS6 service restarted.');

        return $this->redirectToNode($node);
    }

    #[Route(path: '/{id}/delete', name: 'admin_ts6_nodes_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts6_delete_' . $id);

        $this->entityManager->remove($node);
        $this->entityManager->flush();

        $request->getSession()->getFlashBag()->add('success', 'TS6 node deleted.');

        return new Response('', Response::HTTP_FOUND, [
            'Location' => '/admin/ts6/nodes',
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

    private function findNode(int $id): Ts6Node
    {
        $node = $this->nodeRepository->find($id);
        if ($node === null) {
            throw new NotFoundHttpException('TS6 node not found.');
        }

        return $node;
    }

    private function redirectToNode(Ts6Node $node): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => sprintf('/admin/ts6/nodes/%d', $node->getId()),
        ]);
    }

    /**
     * @return array<\App\Module\AgentOrchestrator\Domain\Entity\AgentJob>
     */
    private function loadAgentJobs(Ts6Node $node): array
    {
        return $this->agentJobRepository->findLatestForNodeAndTypes($node->getAgent()->getId(), [
            'ts6.install',
            'ts6.status',
            'ts6.service.action',
        ], 5);
    }

    /**
     * @return array<string, string>
     */
    private function csrfTokens(Ts6Node $node): array
    {
        $id = (int) $node->getId();

        return [
            'install' => $this->csrfTokenManager->getToken('ts6_install_' . $id)->getValue(),
            'show_admin' => $this->csrfTokenManager->getToken('ts6_show_admin_' . $id)->getValue(),
            'start' => $this->csrfTokenManager->getToken('ts6_start_' . $id)->getValue(),
            'stop' => $this->csrfTokenManager->getToken('ts6_stop_' . $id)->getValue(),
            'restart' => $this->csrfTokenManager->getToken('ts6_restart_' . $id)->getValue(),
            'delete' => $this->csrfTokenManager->getToken('ts6_delete_' . $id)->getValue(),
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

    private function applyAgentDefaults(Ts6NodeDto $dto, \Symfony\Component\Form\FormInterface $form): void
    {
        $agentId = trim($dto->agentNodeId);
        if ($agentId === '') {
            $form->addError(new FormError('Agent Node is required.'));
            return;
        }

        $agent = $this->agentRepository->find($agentId);
        if ($agent === null) {
            $form->addError(new FormError('Selected agent was not found.'));

            return;
        }

        $dto->agentBaseUrl = $agent->getAgentBaseUrl();
        $dto->agentApiToken = $agent->getAgentApiToken($this->crypto);

        if (trim($dto->downloadUrl) === '') {
            $dto->downloadUrl = Ts6NodeDto::DEFAULT_DOWNLOAD_URL;
        }

        if (trim($dto->installPath) === '' && $dto->osType !== 'windows') {
            $dto->installPath = Ts6NodeDto::DEFAULT_INSTALL_PATH;
        }

        if (trim($dto->instanceName) === '') {
            $dto->instanceName = Ts6NodeDto::DEFAULT_INSTANCE_NAME;
        }

        if (trim($dto->serviceName) === '') {
            $dto->serviceName = Ts6NodeDto::DEFAULT_SERVICE_NAME;
        }

        if (trim($dto->installPath) === '') {
            $form->addError(new FormError('Install path is required.'));
        }

        if (trim($dto->downloadUrl) === '') {
            $form->addError(new FormError('Download URL is required.'));
        }

        if (trim($dto->instanceName) === '') {
            $form->addError(new FormError('Instance name is required.'));
        }

        if (trim($dto->serviceName) === '') {
            $form->addError(new FormError('Service name is required.'));
        }
    }

    private function buildInstallDto(Ts6Node $node): InstallDto
    {
        $adminPassword = $node->getAdminPassword($this->crypto);
        if ($adminPassword === null) {
            $adminPassword = bin2hex(random_bytes(12));
            $node->setAdminPassword($adminPassword, $this->crypto);
            $this->entityManager->flush();
        }

        return new InstallDto(
            $node->getDownloadUrl(),
            $node->getInstallPath(),
            $node->getInstanceName(),
            $node->getServiceName(),
            true,
            ['0.0.0.0', '::'],
            $node->getVoicePort(),
            30033,
            ['0.0.0.0', '::'],
            true,
            $node->getQueryBindIp(),
            $node->getQueryHttpsPort(),
            $adminPassword,
        );
    }
}
