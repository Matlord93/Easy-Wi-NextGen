<?php

declare(strict_types=1);

namespace App\Module\Teamspeak\UI\Controller\Customer;

use App\Module\Core\Dto\Ts6\ViewerDto;
use App\Module\Core\Domain\Entity\Ts6Token;
use App\Module\Core\Domain\Entity\Ts6VirtualServer;
use App\Module\Core\Domain\Entity\User;
use App\Module\Core\Domain\Enum\UserType;
use App\Module\Core\Form\Ts6ViewerType;
use App\Repository\Ts6TokenRepository;
use App\Repository\Ts6VirtualServerRepository;
use App\Repository\Ts6ViewerRepository;
use App\Module\Core\Application\SecretsCrypto;
use App\Module\Core\Application\Ts6\Ts6VirtualServerService;
use App\Module\Core\Application\Ts6\Ts6ViewerService;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route(path: '/customer/ts6/servers')]
final class CustomerTs6ServerController
{
    public function __construct(
        private readonly Ts6VirtualServerRepository $virtualServerRepository,
        private readonly Ts6TokenRepository $tokenRepository,
        private readonly Ts6ViewerRepository $viewerRepository,
        private readonly Ts6VirtualServerService $virtualServerService,
        private readonly Ts6ViewerService $viewerService,
        private readonly SecretsCrypto $crypto,
        private readonly FormFactoryInterface $formFactory,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
    ) {
    }

    #[Route(path: '', name: 'customer_ts6_servers_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $customer = $this->requireCustomer($request);

        $servers = $this->virtualServerRepository->findBy(
            ['customerId' => $customer->getId(), 'archivedAt' => null],
            ['updatedAt' => 'DESC'],
        );

        return new Response($this->twig->render('customer/infrastructure/voiceservers/index.html.twig', [
            'activeNav' => 'voiceservers',
            'servers' => $servers,
            'voiceBaseUrl' => '/customer/ts6/servers',
            'voiceTitle' => 'TeamSpeak 6',
        ]));
    }

    #[Route(path: '/{id}', name: 'customer_ts6_servers_show', methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);

        return new Response($this->twig->render('customer/ts6/servers/show.html.twig', [
            'activeNav' => 'ts6',
            'server' => $server,
            'csrf' => $this->csrfTokens($server),
        ]));
    }

    #[Route(path: '/{id}/start', name: 'customer_ts6_servers_start', methods: ['POST'])]
    public function start(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts6_server_start_' . $id);

        $this->virtualServerService->start($server);
        $request->getSession()->getFlashBag()->add('success', 'Server gestartet.');

        return $this->redirectToServer($server);
    }

    #[Route(path: '/{id}/stop', name: 'customer_ts6_servers_stop', methods: ['POST'])]
    public function stop(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts6_server_stop_' . $id);

        $this->virtualServerService->stop($server);
        $request->getSession()->getFlashBag()->add('success', 'Server gestoppt.');

        return $this->redirectToServer($server);
    }

    #[Route(path: '/{id}/recreate', name: 'customer_ts6_servers_recreate', methods: ['POST'])]
    public function recreate(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts6_server_recreate_' . $id);

        $replacement = $this->virtualServerService->recreate($server);
        $request->getSession()->getFlashBag()->add('success', 'Server neu erstellt.');

        return $this->redirectToServer($replacement);
    }

    #[Route(path: '/{id}/token', name: 'customer_ts6_servers_token', methods: ['GET'])]
    public function token(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);

        $token = $this->tokenRepository->findOneBy(['virtualServer' => $server, 'active' => true]);

        return new Response($this->twig->render('customer/ts6/servers/token.html.twig', [
            'activeNav' => 'ts6',
            'server' => $server,
            'token' => $token instanceof Ts6Token ? $token->getToken($this->crypto) : null,
            'csrf' => $this->csrfTokens($server),
        ]));
    }

    #[Route(path: '/{id}/token/rotate', name: 'customer_ts6_servers_token_rotate', methods: ['POST'])]
    public function rotateToken(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);
        $this->validateCsrf($request, 'ts6_server_token_rotate_' . $id);

        $this->virtualServerService->rotateToken($server);
        $request->getSession()->getFlashBag()->add('success', 'Token rotiert.');

        return $this->redirectToToken($server);
    }

    #[Route(path: '/{id}/viewer', name: 'customer_ts6_servers_viewer', methods: ['GET', 'POST'])]
    public function viewer(Request $request, int $id): Response
    {
        $customer = $this->requireCustomer($request);
        $server = $this->findServer($customer, $id);

        $viewer = $this->viewerRepository->findOneBy(['virtualServer' => $server]);
        $dto = new ViewerDto(
            $viewer?->isEnabled() ?? true,
            $viewer?->getCacheTtlMs() ?? 1500,
            $viewer?->getDomainAllowlist() ?? null,
        );

        $form = $this->formFactory->create(Ts6ViewerType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $viewer = $this->viewerService->enableViewer($server, $dto);
            $request->getSession()->getFlashBag()->add('success', 'Viewer Einstellungen gespeichert.');
        }

        return new Response($this->twig->render('customer/ts6/servers/viewer.html.twig', [
            'activeNav' => 'ts6',
            'server' => $server,
            'viewer' => $viewer,
            'form' => $form->createView(),
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

    private function findServer(User $customer, int $id): Ts6VirtualServer
    {
        $server = $this->virtualServerRepository->findOneBy([
            'id' => $id,
            'customerId' => $customer->getId(),
            'archivedAt' => null,
        ]);
        if ($server === null) {
            throw new NotFoundHttpException('TS6 virtual server not found.');
        }

        return $server;
    }

    private function redirectToServer(Ts6VirtualServer $server): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => sprintf('/customer/ts6/servers/%d', $server->getId()),
        ]);
    }

    private function redirectToToken(Ts6VirtualServer $server): Response
    {
        return new Response('', Response::HTTP_FOUND, [
            'Location' => sprintf('/customer/ts6/servers/%d/token', $server->getId()),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function csrfTokens(Ts6VirtualServer $server): array
    {
        $id = (int) $server->getId();

        return [
            'start' => $this->csrfTokenManager->getToken('ts6_server_start_' . $id)->getValue(),
            'stop' => $this->csrfTokenManager->getToken('ts6_server_stop_' . $id)->getValue(),
            'recreate' => $this->csrfTokenManager->getToken('ts6_server_recreate_' . $id)->getValue(),
            'rotate' => $this->csrfTokenManager->getToken('ts6_server_token_rotate_' . $id)->getValue(),
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
