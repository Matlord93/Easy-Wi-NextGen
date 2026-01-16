<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Ts6\InstallDto;
use App\Dto\Ts6\Ts6NodeDto;
use App\Entity\Ts6Node;
use App\Entity\User;
use App\Form\Ts6NodeType;
use App\Repository\Ts6NodeRepository;
use App\Service\SecretsCrypto;
use App\Service\Ts6\Ts6NodeService;
use Doctrine\ORM\EntityManagerInterface;
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
            'activeNav' => 'ts6',
            'nodes' => $nodes,
        ]));
    }

    #[Route(path: '/new', name: 'admin_ts6_nodes_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->requireAdmin($request);

        $dto = new Ts6NodeDto();
        $form = $this->formFactory->create(Ts6NodeType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $node = new Ts6Node(
                $dto->name,
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
            $this->entityManager->persist($node);
            $this->entityManager->flush();

            $request->getSession()->getFlashBag()->add('success', 'TS6 node created.');

            return new Response('', Response::HTTP_FOUND, [
                'Location' => sprintf('/admin/ts6/nodes/%d', $node->getId()),
            ]);
        }

        return new Response($this->twig->render('admin/ts6/nodes/new.html.twig', [
            'activeNav' => 'ts6',
            'form' => $form->createView(),
        ]));
    }

    #[Route(path: '/{id}', name: 'admin_ts6_nodes_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $this->requireAdmin($request);

        $node = $this->findNode($id);

        return new Response($this->twig->render('admin/ts6/nodes/show.html.twig', [
            'activeNav' => 'ts6',
            'node' => $node,
            'admin_password' => null,
            'csrf' => $this->csrfTokens($node),
        ]));
    }

    #[Route(path: '/{id}/install', name: 'admin_ts6_nodes_install', methods: ['POST'])]
    public function install(Request $request, int $id): Response
    {
        $this->requireAdmin($request);
        $node = $this->findNode($id);
        $this->validateCsrf($request, 'ts6_install_' . $id);

        $dto = new InstallDto(
            $node->getDownloadUrl(),
            $node->getInstallPath(),
            $node->getInstanceName(),
            $node->getServiceName(),
            true,
            ['0.0.0.0', '::'],
            9987,
            30033,
            ['0.0.0.0', '::'],
            true,
            $node->getQueryBindIp(),
            $node->getQueryHttpsPort(),
            null,
        );

        $this->nodeService->install($node, $dto);
        $request->getSession()->getFlashBag()->add('success', 'TS6 install queued.');

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
            'activeNav' => 'ts6',
            'node' => $node,
            'admin_password' => $adminPassword,
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
        ];
    }

    private function validateCsrf(Request $request, string $tokenId): void
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $token))) {
            throw new UnauthorizedHttpException('csrf', 'Invalid CSRF token.');
        }
    }
}
